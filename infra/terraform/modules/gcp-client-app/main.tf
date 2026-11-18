# One delivered client app on GCP: Cloud Run, an optional Cloud SQL instance, an uptime check from three regions
# with an alert that fires only when two or more regions fail for two minutes (the estate platform's paging rule),
# and a budget. Labels carry the client so billing exports split cost per client.

locals {
  name   = "${var.client}-${var.app}"
  labels = { client = var.client, app = var.app, managed-by = "client-estate-ops" }
}

data "google_project" "this" {
  project_id = var.project_id
}

resource "google_cloud_run_v2_service" "app" {
  project  = var.project_id
  name     = local.name
  location = var.region
  ingress  = "INGRESS_TRAFFIC_ALL"
  labels   = local.labels

  template {
    labels = local.labels
    scaling {
      min_instance_count = var.min_instances
      max_instance_count = var.max_instances
    }
    containers {
      image = var.image
      ports {
        container_port = 8080
      }
      resources {
        limits = {
          cpu    = var.cpu
          memory = var.memory
        }
      }
      dynamic "env" {
        for_each = var.environment
        content {
          name  = env.key
          value = env.value
        }
      }
      startup_probe {
        http_get {
          path = var.health_path
        }
      }
    }
  }

  traffic {
    type    = "TRAFFIC_TARGET_ALLOCATION_TYPE_LATEST"
    percent = 100
  }

  lifecycle {
    # The estate platform shifts traffic during canaries; Terraform does not fight it.
    ignore_changes = [traffic]
  }
}

resource "google_cloud_run_v2_service_iam_member" "public" {
  project  = var.project_id
  location = var.region
  name     = google_cloud_run_v2_service.app.name
  role     = "roles/run.invoker"
  member   = "allUsers"
}

resource "google_sql_database_instance" "db" {
  count               = var.database_version == null ? 0 : 1
  project             = var.project_id
  name                = local.name
  region              = var.region
  database_version    = var.database_version
  deletion_protection = true
  settings {
    tier        = var.db_tier
    user_labels = local.labels
    backup_configuration {
      enabled                        = true
      point_in_time_recovery_enabled = startswith(var.database_version, "POSTGRES")
    }
    ip_configuration {
      ipv4_enabled = true
      ssl_mode     = "ENCRYPTED_ONLY"
    }
  }
}

resource "google_sql_database" "app" {
  count    = var.database_version == null ? 0 : 1
  project  = var.project_id
  name     = var.app
  instance = google_sql_database_instance.db[0].name
}

resource "google_monitoring_notification_channel" "email" {
  project      = var.project_id
  display_name = "${local.name} on-call"
  type         = "email"
  labels       = { email_address = var.alert_email }
}

resource "google_monitoring_uptime_check_config" "public" {
  project          = var.project_id
  display_name     = local.name
  timeout          = "10s"
  period           = "60s"
  selected_regions = ["EUROPE", "USA_VIRGINIA", "ASIA_PACIFIC"]
  http_check {
    path         = var.health_path
    port         = 443
    use_ssl      = true
    validate_ssl = true
  }
  monitored_resource {
    type = "uptime_url"
    labels = {
      project_id = var.project_id
      host       = var.domain
    }
  }
}

resource "google_monitoring_alert_policy" "down" {
  project               = var.project_id
  display_name          = "${local.name} down from two or more regions"
  combiner              = "OR"
  notification_channels = [google_monitoring_notification_channel.email.id]
  conditions {
    display_name = "uptime check failing in more than one region"
    condition_threshold {
      filter          = "metric.type=\"monitoring.googleapis.com/uptime_check/check_passed\" AND resource.type=\"uptime_url\" AND metric.label.check_id=\"${google_monitoring_uptime_check_config.public.uptime_check_id}\""
      duration        = "120s"
      comparison      = "COMPARISON_GT"
      threshold_value = 1
      aggregations {
        alignment_period     = "60s"
        per_series_aligner   = "ALIGN_NEXT_OLDER"
        cross_series_reducer = "REDUCE_COUNT_FALSE"
        group_by_fields      = ["resource.label.project_id", "resource.label.host"]
      }
      trigger {
        count = 1
      }
    }
  }
}

resource "google_billing_budget" "app" {
  count           = var.billing_account == null ? 0 : 1
  billing_account = var.billing_account
  display_name    = local.name
  budget_filter {
    projects = ["projects/${data.google_project.this.number}"]
    labels   = { client = var.client }
  }
  amount {
    specified_amount {
      currency_code = "EUR"
      units         = tostring(var.monthly_budget_eur)
    }
  }
  threshold_rules {
    threshold_percent = 0.8
    spend_basis       = "FORECASTED_SPEND"
  }
  threshold_rules {
    threshold_percent = 1.0
  }
}

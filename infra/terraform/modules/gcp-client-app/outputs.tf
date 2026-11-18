output "url" {
  value = google_cloud_run_v2_service.app.uri
}

output "service" {
  value = google_cloud_run_v2_service.app.name
}

output "uptime_check_id" {
  value = google_monitoring_uptime_check_config.public.uptime_check_id
}

output "database_connection_name" {
  value = var.database_version == null ? null : google_sql_database_instance.db[0].connection_name
}

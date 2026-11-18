terraform {
  required_version = ">= 1.6"
}

provider "google" {
  project = "agency-clients-prod"
  region  = "europe-west3"
}

module "platform" {
  source             = "../../modules/gcp-client-app"
  project_id         = "agency-clients-prod"
  client             = "sonnwald-energie"
  app                = "platform"
  image              = "europe-docker.pkg.dev/agency-clients-prod/apps/sonnwald-platform@sha256:0000000000000000000000000000000000000000000000000000000000000000"
  domain             = "platform.sonnwald.example"
  database_version   = "POSTGRES_16"
  alert_email        = "oncall@agency.example"
  billing_account    = "000000-000000-000000"
  monthly_budget_eur = 300
  environment = {
    DJANGO_SETTINGS_MODULE = "platform.settings.production"
  }
}

output "platform_url" {
  value = module.platform.url
}

variable "project_id" {
  type = string
}

variable "region" {
  type    = string
  default = "europe-west3"
}

variable "client" {
  type = string
  validation {
    condition     = can(regex("^[a-z0-9-]{2,24}$", var.client))
    error_message = "client must be 2-24 lower-case letters, digits or hyphens"
  }
}

variable "app" {
  type = string
}

variable "image" {
  description = "Container image with an immutable tag or digest"
  type        = string
}

variable "domain" {
  description = "Public host name the uptime check probes"
  type        = string
}

variable "health_path" {
  type    = string
  default = "/healthz"
}

variable "min_instances" {
  type    = number
  default = 0
}

variable "max_instances" {
  type    = number
  default = 4
}

variable "cpu" {
  type    = string
  default = "1"
}

variable "memory" {
  type    = string
  default = "512Mi"
}

variable "database_version" {
  description = "Cloud SQL version (MYSQL_8_0, POSTGRES_16) or null for no database"
  type        = string
  default     = "POSTGRES_16"
}

variable "db_tier" {
  type    = string
  default = "db-g1-small"
}

variable "environment" {
  type    = map(string)
  default = {}
}

variable "alert_email" {
  type = string
}

variable "billing_account" {
  description = "Billing account id for the budget; null to skip the budget"
  type        = string
  default     = null
}

variable "monthly_budget_eur" {
  type    = number
  default = 250
}

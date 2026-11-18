variable "client" {
  description = "Client id (lower case, no spaces); every resource is tagged with it so cost lands on the client"
  type        = string
  validation {
    condition     = can(regex("^[a-z0-9-]{2,24}$", var.client))
    error_message = "client must be 2-24 lower-case letters, digits or hyphens"
  }
}

variable "app" {
  description = "App name within the client (shop, portal, api)"
  type        = string
}

variable "image" {
  description = "Container image with an immutable tag or digest"
  type        = string
}

variable "container_port" {
  type    = number
  default = 8080
}

variable "health_path" {
  type    = string
  default = "/healthz"
}

variable "cpu" {
  type    = number
  default = 512
}

variable "memory" {
  type    = number
  default = 1024
}

variable "desired_count" {
  type    = number
  default = 2
}

variable "vpc_id" {
  type = string
}

variable "public_subnet_ids" {
  type = list(string)
}

variable "private_subnet_ids" {
  type = list(string)
}

variable "certificate_arn" {
  description = "ACM certificate for the app's domain"
  type        = string
}

variable "domain" {
  description = "The app's public host name, probed from three regions"
  type        = string
}

variable "db_engine" {
  description = "mysql or postgres; null for an app without a database"
  type        = string
  default     = "mysql"
  validation {
    condition     = var.db_engine == null || contains(["mysql", "postgres"], coalesce(var.db_engine, "none"))
    error_message = "db_engine must be mysql, postgres or null"
  }
}

variable "db_instance_class" {
  type    = string
  default = "db.t4g.small"
}

variable "environment" {
  description = "Plain environment variables for the container"
  type        = map(string)
  default     = {}
}

variable "alert_email" {
  description = "Where alarms go (the agency's on-call address)"
  type        = string
}

variable "monthly_budget_usd" {
  description = "Monthly cost budget for this app; alerts at 80% and 100% of forecast"
  type        = number
  default     = 300
}

variable "tags" {
  type    = map(string)
  default = {}
}

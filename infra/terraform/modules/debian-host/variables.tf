variable "client" {
  type = string
}

variable "app" {
  type = string
}

variable "instance_type" {
  type    = string
  default = "t3.small"
}

variable "subnet_id" {
  type = string
}

variable "vpc_id" {
  type = string
}

variable "admin_cidrs" {
  description = "Networks allowed to reach SSH (the agency VPN); empty closes SSH and leaves SSM"
  type        = list(string)
  default     = []
}

variable "admin_keys" {
  description = "SSH public keys for the deploy user"
  type        = list(string)
  default     = []
}

variable "php_version" {
  description = "PHP from Debian's own packages for legacy PHP sites, or null"
  type        = string
  default     = "8.2"
}

variable "tags" {
  type    = map(string)
  default = {}
}

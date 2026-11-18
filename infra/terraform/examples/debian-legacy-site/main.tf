terraform {
  required_version = ">= 1.6"
}

provider "aws" {
  region = "eu-central-1"
}

module "legacy" {
  source      = "../../modules/debian-host"
  client      = "kaffee-krone"
  app         = "legacy-site"
  vpc_id      = "vpc-0123456789abcdef0"
  subnet_id   = "subnet-0aaaaaaaaaaaaaaa1"
  admin_cidrs = ["10.20.0.0/16"]
  admin_keys  = ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleKeyOnlyForValidation deploy@agency"]
  php_version = "8.2"
}

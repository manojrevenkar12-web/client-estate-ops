# Example: a Laravel shop for one client on AWS. `terraform init -backend=false && terraform validate` runs in CI;
# a real deployment adds a backend (S3 + DynamoDB lock) and the client's network ids.

terraform {
  required_version = ">= 1.6"
}

provider "aws" {
  region = "eu-central-1"
}

module "shop" {
  source             = "../../modules/aws-client-app"
  client             = "brauhaus-kessler"
  app                = "shop"
  image              = "ghcr.io/agency/brauhaus-shop@sha256:0000000000000000000000000000000000000000000000000000000000000000"
  domain             = "shop.brauhaus-kessler.example"
  vpc_id             = "vpc-0123456789abcdef0"
  public_subnet_ids  = ["subnet-0aaaaaaaaaaaaaaa1", "subnet-0aaaaaaaaaaaaaaa2"]
  private_subnet_ids = ["subnet-0bbbbbbbbbbbbbbb1", "subnet-0bbbbbbbbbbbbbbb2"]
  certificate_arn    = "arn:aws:acm:eu-central-1:123456789012:certificate/00000000-0000-0000-0000-000000000000"
  db_engine          = "mysql"
  alert_email        = "oncall@agency.example"
  monthly_budget_usd = 400
  environment = {
    APP_ENV     = "production"
    LOG_CHANNEL = "stderr"
  }
}

output "shop_url" {
  value = module.shop.url
}

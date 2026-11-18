# A Debian 12 host for the legacy client sites that are not containerised yet, provisioned so that it stays patched:
# unattended security upgrades with automatic reboots in a window, SSH keys only, fail2ban, an nftables firewall,
# node exporter for metrics, and IMDSv2 and an encrypted disk at the AWS level.

locals {
  name = "${var.client}-${var.app}"
  tags = merge(var.tags, { client = var.client, app = var.app, managed-by = "client-estate-ops" })
}

data "aws_ami" "debian" {
  most_recent = true
  owners      = ["136693071363"] # Debian's official account
  filter {
    name   = "name"
    values = ["debian-12-amd64-*"]
  }
  filter {
    name   = "architecture"
    values = ["x86_64"]
  }
}

resource "aws_security_group" "host" {
  name        = local.name
  description = "HTTPS from anywhere; SSH only from the agency's networks"
  vpc_id      = var.vpc_id
  ingress {
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }
  ingress {
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }
  dynamic "ingress" {
    for_each = length(var.admin_cidrs) > 0 ? [1] : []
    content {
      from_port   = 22
      to_port     = 22
      protocol    = "tcp"
      cidr_blocks = var.admin_cidrs
    }
  }
  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
  tags = local.tags
}

resource "aws_instance" "host" {
  ami                    = data.aws_ami.debian.id
  instance_type          = var.instance_type
  subnet_id              = var.subnet_id
  vpc_security_group_ids = [aws_security_group.host.id]
  user_data = templatefile("${path.module}/cloud-init.yaml.tftpl", {
    hostname    = local.name
    admin_keys  = var.admin_keys
    php_version = var.php_version
  })
  user_data_replace_on_change = false
  metadata_options {
    http_tokens   = "required"
    http_endpoint = "enabled"
  }
  root_block_device {
    encrypted   = true
    volume_size = 20
    volume_type = "gp3"
  }
  tags        = merge(local.tags, { Name = local.name })
  volume_tags = local.tags
  lifecycle {
    ignore_changes = [ami] # a newer AMI does not replace a running host; unattended-upgrades patches it
  }
}

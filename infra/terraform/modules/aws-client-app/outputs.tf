output "url" {
  value = "https://${var.domain}"
}

output "load_balancer_dns" {
  value = aws_lb.this.dns_name
}

output "service" {
  value = aws_ecs_service.app.name
}

output "health_check_id" {
  description = "Feed this to the estate platform's probe ingestion"
  value       = aws_route53_health_check.public.id
}

output "database_endpoint" {
  value = var.db_engine == null ? null : aws_db_instance.this[0].endpoint
}

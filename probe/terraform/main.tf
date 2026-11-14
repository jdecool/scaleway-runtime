# Deploys the probe function, applied on 2026-11-14 with the scaleway provider v2.92 (see docs/probe-results.md).
# Credentials come from the usual SCW_ACCESS_KEY, SCW_SECRET_KEY and SCW_DEFAULT_PROJECT_ID variables.

terraform {
  required_version = ">= 1.5"

  required_providers {
    scaleway = {
      source  = "scaleway/scaleway"
      version = "~> 2.92"
    }
    archive = {
      source  = "hashicorp/archive"
      version = "~> 2.8"
    }
  }
}

variable "region" {
  type    = string
  default = "fr-par"
}

variable "runtime" {
  type    = string
  default = "php85"
}

provider "scaleway" {
  region = var.region
}

data "archive_file" "probe" {
  type        = "zip"
  source_dir  = "${path.module}/../function"
  output_path = "${path.module}/.build/probe.zip"
}

resource "scaleway_function_namespace" "probe" {
  name        = "scaleway-runtime-probe"
  description = "Probe measuring the PHP runtime behavior, destroy after use"
}

resource "scaleway_function" "probe" {
  namespace_id = scaleway_function_namespace.probe.id
  name         = "probe-${var.runtime}"
  runtime      = var.runtime
  handler      = "handler.handle"
  privacy      = "public"
  # A single instance makes Warm Process reuse observable between consecutive calls.
  min_scale    = 0
  max_scale    = 1
  memory_limit = 256
  timeout      = 60
  zip_file     = data.archive_file.probe.output_path
  zip_hash     = data.archive_file.probe.output_sha256
  deploy       = true

  # Do plain and secret variables both reach getenv() and $_SERVER?
  environment_variables = {
    PROBE_PLAIN = "plain-value"
  }
  secret_environment_variables = {
    PROBE_SECRET = "secret-value"
  }
}

resource "scaleway_function_cron" "probe" {
  function_id = scaleway_function.probe.id
  name        = "probe-cron"
  schedule    = "*/5 * * * *"
  args        = jsonencode({ probe = "cron", nested = { list = [1, 2] } })

  # Replacing the function (e.g. -var runtime=php84) deletes its CRON triggers server side: recreate them in the same apply.
  lifecycle {
    replace_triggered_by = [scaleway_function.probe.id]
  }
}

output "endpoint" {
  value = "https://${scaleway_function.probe.domain_name}"
}

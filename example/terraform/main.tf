# Deploys the example application. Unvalidated: written against the scaleway provider docs (v2.92), never applied.
# Credentials come from the usual SCW_ACCESS_KEY, SCW_SECRET_KEY and SCW_DEFAULT_PROJECT_ID variables.
# Build the zip first: (cd example && vendor/bin/scaleway-package).

terraform {
  required_version = ">= 1.5"

  required_providers {
    scaleway = {
      source  = "scaleway/scaleway"
      version = "~> 2.92"
    }
  }
}

variable "region" {
  type    = string
  default = "fr-par"
}

variable "zip_file" {
  type    = string
  default = "../function.zip"
}

variable "app_secret" {
  type      = string
  sensitive = true
}

provider "scaleway" {
  region = var.region
}

resource "scaleway_function_namespace" "example" {
  name        = "scaleway-runtime-example"
  description = "Example Symfony application of jdecool/scaleway-runtime"
}

resource "scaleway_function" "example" {
  namespace_id = scaleway_function_namespace.example.id
  name         = "example"
  runtime      = "php85"
  handler      = "handler.handle"
  privacy      = "public"
  min_scale    = 0
  # A single instance lets the e2e scenarios observe Warm Process reuse.
  max_scale    = 1
  memory_limit = 256
  timeout      = 60
  zip_file     = var.zip_file
  zip_hash     = filesha256(var.zip_file)
  deploy       = true

  environment_variables = {
    APP_ENV = "prod"
  }
  secret_environment_variables = {
    APP_SECRET = var.app_secret
  }
}

# Calls POST / every hour with the arguments as JSON body.
resource "scaleway_function_cron" "example" {
  function_id = scaleway_function.example.id
  name        = "example-cron"
  schedule    = "0 * * * *"
  args        = jsonencode({ job = "hourly" })
}

output "endpoint" {
  value = "https://${scaleway_function.example.domain_name}"
}

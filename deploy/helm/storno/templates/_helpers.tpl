{{/*
Expand the name of the chart.
*/}}
{{- define "storno.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a default fully-qualified app name.
We truncate at 63 chars because some Kubernetes name fields are limited to this
(by the DNS naming spec). If the release name contains the chart name it will be
used as-is.
*/}}
{{- define "storno.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Create chart label value.
*/}}
{{- define "storno.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels applied to every resource.
*/}}
{{- define "storno.labels" -}}
helm.sh/chart: {{ include "storno.chart" . }}
{{ include "storno.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels — used in Deployment.spec.selector and Service.spec.selector.
These must remain stable across upgrades; never include mutable fields here.
*/}}
{{- define "storno.selectorLabels" -}}
app.kubernetes.io/name: {{ include "storno.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
Component-scoped selector labels.
Usage: {{ include "storno.componentSelectorLabels" (dict "component" "backend" "context" .) }}
*/}}
{{- define "storno.componentSelectorLabels" -}}
app.kubernetes.io/name: {{ include "storno.name" .context }}
app.kubernetes.io/instance: {{ .context.Release.Name }}
app.kubernetes.io/component: {{ .component }}
{{- end }}

{{/*
Component-scoped common labels (selector + chart meta).
Usage: {{ include "storno.componentLabels" (dict "component" "backend" "context" .) }}
*/}}
{{- define "storno.componentLabels" -}}
helm.sh/chart: {{ include "storno.chart" .context }}
{{ include "storno.componentSelectorLabels" (dict "component" .component "context" .context) }}
{{- if .context.Chart.AppVersion }}
app.kubernetes.io/version: {{ .context.Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .context.Release.Service }}
{{- end }}

{{/*
Image pull secrets helper.
*/}}
{{- define "storno.imagePullSecrets" -}}
{{- with .Values.imagePullSecrets }}
imagePullSecrets:
  {{- toYaml . | nindent 2 }}
{{- end }}
{{- end }}

{{/*
Fully-qualified service name helpers.
*/}}
{{- define "storno.backendServiceName" -}}
{{- printf "%s-backend" (include "storno.fullname" .) }}
{{- end }}

{{- define "storno.frontendServiceName" -}}
{{- printf "%s-frontend" (include "storno.fullname" .) }}
{{- end }}

{{- define "storno.redisServiceName" -}}
{{- printf "%s-redis" (include "storno.fullname" .) }}
{{- end }}

{{- define "storno.centrifugoServiceName" -}}
{{- printf "%s-centrifugo" (include "storno.fullname" .) }}
{{- end }}

{{- define "storno.mysqlServiceName" -}}
{{- printf "%s-mysql" (include "storno.fullname" .) }}
{{- end }}

{{/*
Resolve the Redis URL — use the value from values.yaml when set, otherwise
construct it from the in-cluster service name.
*/}}
{{- define "storno.redisUrl" -}}
{{- if .Values.backend.env.REDIS_URL }}
{{- .Values.backend.env.REDIS_URL }}
{{- else }}
{{- printf "redis://%s:6379" (include "storno.redisServiceName" .) }}
{{- end }}
{{- end }}

{{/*
Resolve the Centrifugo API URL for the backend.
*/}}
{{- define "storno.centrifugoApiUrl" -}}
{{- if .Values.backend.env.CENTRIFUGO_API_URL }}
{{- .Values.backend.env.CENTRIFUGO_API_URL }}
{{- else }}
{{- printf "http://%s:8000/api" (include "storno.centrifugoServiceName" .) }}
{{- end }}
{{- end }}

{{/*
Resolve the DATABASE_URL for the backend.
When mysql.enabled=true and DATABASE_URL is not explicitly set, construct the
DSN from the mysql.auth values and the in-cluster service name.
*/}}
{{- define "storno.databaseUrl" -}}
{{- if .Values.backend.env.DATABASE_URL }}
{{- .Values.backend.env.DATABASE_URL }}
{{- else if .Values.mysql.enabled }}
{{- printf "mysql://%s:%s@%s:3306/%s?serverVersion=8.0&charset=utf8mb4" .Values.mysql.auth.username .Values.mysql.auth.password (include "storno.mysqlServiceName" .) .Values.mysql.auth.database }}
{{- else }}
{{- fail "backend.env.DATABASE_URL must be set when mysql.enabled=false" }}
{{- end }}
{{- end }}

{{/*
Resolve the server-side NUXT_API_BASE (internal cluster URL).
*/}}
{{- define "storno.nuxtApiBase" -}}
{{- if .Values.frontend.env.NUXT_API_BASE }}
{{- .Values.frontend.env.NUXT_API_BASE }}
{{- else }}
{{- printf "http://%s" (include "storno.backendServiceName" .) }}
{{- end }}
{{- end }}

{{/*
PVC name helpers.
*/}}
{{- define "storno.jwtKeysPvcName" -}}
{{- printf "%s-jwt-keys" (include "storno.fullname" .) }}
{{- end }}

{{- define "storno.backendVarPvcName" -}}
{{- printf "%s-backend-var" (include "storno.fullname" .) }}
{{- end }}

{{- define "storno.backendStoragePvcName" -}}
{{- printf "%s-backend-storage" (include "storno.fullname" .) }}
{{- end }}

{{- define "storno.redisPvcName" -}}
{{- printf "%s-redis-data" (include "storno.fullname" .) }}
{{- end }}

{{- define "storno.centrifugoPvcName" -}}
{{- printf "%s-centrifugo-data" (include "storno.fullname" .) }}
{{- end }}

{{- define "storno.mysqlPvcName" -}}
{{- printf "%s-mysql-data" (include "storno.fullname" .) }}
{{- end }}

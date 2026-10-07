#!/usr/bin/env bash
# Regenerates rsrc_windows_{amd64,arm64}.syso (manifest + VERSIONINFO) in endpoint-agent/.
# Needs: go install github.com/josephspurrier/goversioninfo/cmd/goversioninfo@v1.7.0
# (github.com/akavel/rsrc, which goversioninfo builds on, only embeds manifests and icons, not VERSIONINFO,
#  and two .syso files with a .rsrc section per architecture cannot be combined, so one tool writes both.)
# The committed .syso files are static (version 1.0.0.0) so builds stay reproducible; the real build
# version is injected with -ldflags and printed by `rivetit-agent version`.
set -euo pipefail
cd "$(dirname "$0")/.."
export PATH="$(go env GOPATH)/bin:$PATH"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
cp -r winres "$tmp/"
(cd "$tmp" && goversioninfo -platform-specific -o resource.syso winres/versioninfo.json)
cp "$tmp/resource_windows_amd64.syso" rsrc_windows_amd64.syso
cp "$tmp/resource_windows_arm64.syso" rsrc_windows_arm64.syso

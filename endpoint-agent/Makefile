# RMM endpoint agent build (Windows and Linux). Requires Go (see go.mod), GNU make/tar and, for `make e2e`, bash.
# Reproducible-build notes are in README.md ("Build") and docs/rmm/AGENT_BUILD.md. No Go on the host:
# run everything inside `docker run golang:<go.mod version>` (see AGENT_BUILD.md).
GO        ?= go
# CI passes VERSION (tag agent-vX.Y.Z -> X.Y.Z, otherwise 0.0.0-dev.<sha>). Locally: make dist VERSION=0.1.0
VERSION   ?= 0.0.0-dev.$(shell git rev-parse --short HEAD 2>/dev/null || echo unknown)
COMMIT    ?= $(shell git rev-parse --short HEAD 2>/dev/null || echo unknown)
LDFLAGS   := -s -w -X main.version=$(VERSION) -X main.commit=$(COMMIT)
BUILDFLAGS:= -trimpath -buildvcs=false
DIST      := dist
export CGO_ENABLED := 0

.PHONY: all build windows linux tarballs packages test vet lint fuzz e2e clean checksums dist clean-dist syso devtools

all: vet test build

build: windows linux

windows:
	mkdir -p $(DIST)
	GOOS=windows GOARCH=amd64 $(GO) build $(BUILDFLAGS) -ldflags '$(LDFLAGS)' -o $(DIST)/rivetit-agent-windows-amd64.exe .
	GOOS=windows GOARCH=arm64 $(GO) build $(BUILDFLAGS) -ldflags '$(LDFLAGS)' -o $(DIST)/rivetit-agent-windows-arm64.exe .

# Linux (systemd) agent: static binaries, no cgo.
linux:
	mkdir -p $(DIST)
	GOOS=linux GOARCH=amd64 $(GO) build $(BUILDFLAGS) -ldflags '$(LDFLAGS)' -o $(DIST)/rivetit-agent-linux-amd64 .
	GOOS=linux GOARCH=arm64 $(GO) build $(BUILDFLAGS) -ldflags '$(LDFLAGS)' -o $(DIST)/rivetit-agent-linux-arm64 .

# Reproducible tarballs: <binary as rivetit-agent> + install-linux.sh, fixed order/owner/mtime, gzip without a timestamp.
tarballs: linux
	cp scripts/install-linux.sh $(DIST)/install-linux.sh
	@set -e; for a in amd64 arm64; do \
	  s=$(DIST)/stage-$$a; rm -rf $$s; mkdir -p $$s; \
	  cp $(DIST)/rivetit-agent-linux-$$a $$s/rivetit-agent; cp scripts/install-linux.sh $$s/install-linux.sh; \
	  chmod 0755 $$s/rivetit-agent $$s/install-linux.sh; \
	  tar --sort=name --mtime='UTC 2000-01-01' --owner=0 --group=0 --numeric-owner --format=gnu -C $$s -cf - rivetit-agent install-linux.sh \
	    | gzip -n -9 > $(DIST)/rivetit-agent-linux-$$a.tar.gz; \
	  rm -rf $$s; \
	done

# .deb and .rpm for both architectures with nfpm (https://nfpm.goreleaser.com), run in Docker so the host needs no
# tooling. NOT part of `dist`/SHA256SUMS: packaging tools stamp their own metadata. Output: $(DIST)/packages/.
NFPM_IMAGE ?= goreleaser/nfpm:v2.41.1
packages: linux
	mkdir -p $(DIST)/packages
	@set -e; for a in amd64 arm64; do for f in deb rpm; do \
	  docker run --rm -u "$$(id -u):$$(id -g)" -e SOURCE_DATE_EPOCH=946684800 -e VERSION='$(VERSION)' -e ARCH=$$a \
	    -e BIN=$(DIST)/rivetit-agent-linux-$$a -v "$$PWD":/src -w /src $(NFPM_IMAGE) \
	    package --config packaging/nfpm.yaml --packager $$f --target $(DIST)/packages/; \
	done; done

vet:
	$(GO) vet ./...
	GOOS=windows GOARCH=amd64 $(GO) vet ./...
	GOOS=windows GOARCH=arm64 $(GO) vet ./...

# staticcheck is optional: go install honnef.co/go/tools/cmd/staticcheck@latest
lint:
	@command -v staticcheck >/dev/null && staticcheck ./... || echo "staticcheck not installed; skipped"

test:
	CGO_ENABLED=1 $(GO) test -race -count=1 ./...

# TEST/DEV-ONLY binary with the `stamp` command (never part of dist/).
devtools:
	mkdir -p $(DIST)
	$(GO) build $(BUILDFLAGS) -tags devtools -o $(DIST)/rivetit-agent-devtools .

# Regenerate the committed Windows resources (manifest + version info); see winres/gen.sh.
syso:
	./winres/gen.sh

fuzz:
	$(GO) test -run xxx -fuzz=FuzzEmbedded -fuzztime=30s ./internal/embed
	$(GO) test -run xxx -fuzz=FuzzCanonical -fuzztime=30s ./internal/jobs
	$(GO) test -run xxx -fuzz=FuzzDecoders -fuzztime=30s ./internal/api

e2e:
	./e2e/run_e2e.sh

# Checksums cover exactly the release artifacts (no dev tools, no stale files).
checksums:
	cd $(DIST) && sha256sum rivetit-agent-windows-amd64.exe rivetit-agent-windows-arm64.exe \
	  rivetit-agent-linux-amd64 rivetit-agent-linux-arm64 rivetit-agent-linux-amd64.tar.gz rivetit-agent-linux-arm64.tar.gz install-linux.sh > SHA256SUMS

# Clean, reproducible release set: the two Windows exes, the two Linux binaries, their tarballs, install-linux.sh and SHA256SUMS.
dist: clean-dist build tarballs checksums

clean-dist:
	rm -rf $(DIST)

clean:
	rm -rf $(DIST) e2e/work

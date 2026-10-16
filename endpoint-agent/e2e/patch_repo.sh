#!/bin/bash
# patch_repo.sh <repo dir> <suite> <spec>...  --  builds a tiny signed-by-nothing (trusted=yes) apt repository with a real dists/<suite>/main layout, WITHOUT network or apt-utils.
# A spec is NAME=VERSION or NAME=VERSION:POSTINST-BODY (a shell snippet for the package's postinst; keep it harmless). Used by run_patch_proof.sh inside the test container:
#   patch_repo.sh /srv/repo noble-security rivetit-dummy=1.0 rivetit-dummy=1.1 'rivetit-slow=1.0:sleep 120'
set -eu
REPO=$1; SUITE=$2; shift 2
POOL="$REPO/pool/main"; BIN="$REPO/dists/$SUITE/main/binary-amd64"
rm -rf "$REPO"; mkdir -p "$POOL" "$BIN"
for spec in "$@"; do
  nv=${spec%%:*}; body=""; [ "$nv" != "$spec" ] && body=${spec#*:}
  N=${nv%%=*}; V=${nv#*=}
  D=$(mktemp -d); mkdir -p "$D/DEBIAN" "$D/usr/share/$N"
  cat >"$D/DEBIAN/control" <<C
Package: $N
Version: $V
Architecture: all
Maintainer: RivetIT proof <proof@example.invalid>
Description: harmless dummy package for the patch proof
 Installs one file.
C
  echo "version $V" >"$D/usr/share/$N/VERSION"
  if [ -n "$body" ]; then printf '#!/bin/sh\nset -e\n%s\nexit 0\n' "$body" >"$D/DEBIAN/postinst"; chmod 755 "$D/DEBIAN/postinst"; fi
  dpkg-deb --build -Zgzip "$D" "$POOL/${N}_${V}_all.deb" >/dev/null
  rm -rf "$D"
done
: >"$BIN/Packages"
for f in "$POOL"/*.deb; do
  b=$(basename "$f")
  { dpkg-deb -f "$f"; echo "Filename: pool/main/$b"; echo "Size: $(stat -c %s "$f")"; echo "SHA256: $(sha256sum "$f" | cut -d' ' -f1)"; echo; } >>"$BIN/Packages"
done
cat >"$REPO/dists/$SUITE/Release" <<R
Origin: RivetIT proof
Label: RivetIT proof
Suite: $SUITE
Codename: $SUITE
Date: $(date -Ru)
Architectures: amd64
Components: main
SHA256:
 $(sha256sum "$BIN/Packages" | cut -d' ' -f1) $(stat -c %s "$BIN/Packages") main/binary-amd64/Packages
R

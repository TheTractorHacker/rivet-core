#!/bin/bash
# software_repo.sh <repo dir> <suite> <spec>...  --  builds a tiny trusted=yes apt repository (real dists/<suite>/main layout, no network, no apt-utils) for the software deployment proof.
# A spec is NAME=VERSION, optionally with  @DEPENDS  (a Depends: line) and  :POSTINST-BODY  (a harmless shell snippet), in that order:
#   software_repo.sh /srv/repo stable rivetit-dummy=1.0 rivetit-dummy=1.5 rivetit-base=1.0 rivetit-app=1.0@rivetit-base 'rivetit-slow=1.0:sleep 100'
set -eu
REPO=$1; SUITE=$2; shift 2
POOL="$REPO/pool/main"; BIN="$REPO/dists/$SUITE/main/binary-amd64"
rm -rf "$REPO"; mkdir -p "$POOL" "$BIN"
for spec in "$@"; do
  body=""; deps=""; rest=$spec
  case "$rest" in *:*) body=${rest#*:}; rest=${rest%%:*};; esac
  case "$rest" in *@*) deps=${rest#*@}; rest=${rest%%@*};; esac
  N=${rest%%=*}; V=${rest#*=}
  D=$(mktemp -d); mkdir -p "$D/DEBIAN" "$D/usr/share/$N"
  { echo "Package: $N"; echo "Version: $V"; echo "Architecture: all"; echo "Maintainer: RivetIT proof <proof@example.invalid>"
    [ -n "$deps" ] && echo "Depends: $deps"
    echo "Description: harmless dummy package for the software deployment proof"; echo " Installs one file."; } >"$D/DEBIAN/control"
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

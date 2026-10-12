#!/usr/bin/env bash
# Builds the RivetIT Agent MSI TEMPLATE for windows/amd64 with msitools wixl and checks the result. Runs INSIDE the build image (packaging/msi/Dockerfile);
# `make msi` starts it. Linux only, no Windows involved, so the result is PARSE-CHECKED, never run: see docs/rmm/MSI_VERIFY.md for what to run on a Windows PC.
#
#   build.sh build <version> <agent.exe> <out.msi>     build, then check
#   build.sh check <version> <file.msi> [agent.exe]    check an existing file (also what the tests call)
#
# Reproducible: the clock is fixed (faketime), the file times are fixed, the GUIDs are derived from the version and the exe's SHA-256, the component GUID and the
# upgrade code are constants. Two builds of the same exe and version give the same bytes (make msi builds twice and compares).
#
# arm64: wixl 0.103 supports the architectures x86, x64 and intel64 only (`wixl -a arm64` is refused and the Package Platform attribute is ignored), so there is
# NO arm64 MSI. An ARM64 PC installs the exe installer (or runs the x64 MSI, which then installs the x64 agent under emulation: not what you want).
set -euo pipefail

SDE=946684800                      # 2000-01-01T00:00:00Z
FAKETIME='@2000-01-01 00:00:00'
HERE="$(cd "$(dirname "$0")" && pwd)"
# Constants. NEVER change them: a different UpgradeCode makes the product unable to upgrade the ones already installed, a different component GUID breaks
# the reference counting of a major upgrade.
UPGRADE_CODE_AMD64='364538bf-62d7-4685-84c5-43b83b318852'
COMPONENT_GUID_AMD64='b58f6592-8f0f-4bb2-9233-297663489d48'

die() { echo "msi: $*" >&2; exit 1; }

# guid <string>: a name-based (version 5 style) GUID, lower case. Windows Installer wants GUIDs in UPPER case in its tables, so they are upper-cased where they go in.
guid() {
  local h; h="$(printf '%s' "$1" | sha256sum | cut -c1-32)"
  printf '%s-%s-5%s-%x%s-%s' "${h:0:8}" "${h:8:4}" "${h:13:3}" $(( (0x${h:16:1} & 3) | 8 )) "${h:17:3}" "${h:20:12}"
}

# msi_version <agent version>: Windows Installer compares major.minor.build only (major < 256, minor < 256, build < 65536); a pre-release suffix is dropped.
msi_version() {
  [[ "$1" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+) ]] || die "version '$1' does not start with major.minor.patch"
  local a="${BASH_REMATCH[1]}" b="${BASH_REMATCH[2]}" c="${BASH_REMATCH[3]}"
  (( 10#$a < 256 && 10#$b < 256 && 10#$c < 65536 )) || die "version '$1' is outside the MSI range (255.255.65535)"
  printf '%d.%d.%d' "$((10#$a))" "$((10#$b))" "$((10#$c))"
}

# check <version> <msi> [exe]: the ICE-like sanity check. Dumps the tables with msidump and asserts what the install depends on. Prints "ok:" lines.
check() {
  local version="$1" msi="$2" exe="${3:-}" mv d t
  mv="$(msi_version "$version")"
  [ -f "$msi" ] || die "$msi not found"
  d="$(mktemp -d)"
  [ "$(head -c 8 "$msi" | od -An -tx1 | tr -d ' \n')" = d0cf11e0a1b11ae1 ] || die "not an OLE compound file (D0CF11E0A1B11AE1)"
  echo "ok: OLE compound file header"
  msidump -t -d "$d" "$msi" >/dev/null 2>&1 || die "msidump cannot read the tables"
  t="$(msiinfo tables "$msi" | tr '\n' ' ')"
  for tbl in Property Directory Component File Feature FeatureComponents Media CustomAction InstallExecuteSequence Upgrade LaunchCondition; do
    case " $t " in *" $tbl "*) ;; *) die "table $tbl is missing" ;; esac
  done
  echo "ok: tables Property Directory Component File Feature FeatureComponents Media CustomAction InstallExecuteSequence Upgrade LaunchCondition"
  # idt files: line 1 column names, 2 types, 3 table + keys, then rows (tab separated)
  idt() { tr -d '\r' < "$d/$1.idt"; }
  row() { idt "$1" | awk -F'\t' -v k="$2" 'NR>3 && $1==k'; }
  col() { row "$1" "$2" | awk -F'\t' -v c="$3" '{print $c}'; }
  [ "$(col Property ProductVersion 2)" = "$mv" ] || die "ProductVersion is not $mv"
  [ "$(col Property ALLUSERS 2)" = 1 ] || die "ALLUSERS is not 1 (per-machine)"
  [ "$(col Property ProductName 2)" = "RivetIT Agent" ] || die "ProductName"
  [ "$(col Property UpgradeCode 2)" = "{${UPGRADE_CODE_AMD64^^}}" ] || die "UpgradeCode is not the constant one"
  [ "$(col Property MsiHiddenProperties 2)" = ENROLL_TOKEN ] || die "the enrollment token is not hidden from the log"
  echo "ok: ProductVersion $mv, per-machine, stable UpgradeCode, ENROLL_TOKEN hidden"
  [ "$(col Component AgentExe 2)" = "{${COMPONENT_GUID_AMD64^^}}" ] || die "component GUID is not the constant one"
  [ "$(col Component AgentExe 4)" = 256 ] || die "the component is not 64-bit (attribute 256)"
  [ "$(col Component AgentExe 3)" = INSTALLDIR ] || die "component directory"
  [ "$(col Directory VendorDir 2)" = ProgramFiles64Folder ] || die "not installed under ProgramFiles64Folder"
  [ "$(col Directory INSTALLDIR 3)" = Agent ] || die "install directory name"
  [ "$(col File AgentExeFile 3)" = rivetit-agent.exe ] || die "the file is not rivetit-agent.exe"
  echo "ok: rivetit-agent.exe, 64-bit component {$COMPONENT_GUID_AMD64} (stable) in %ProgramFiles%\\RivetIT\\Agent"
  # upgrade: a major upgrade over older versions and a block on downgrades, both for the stable code
  [ "$(awk -F'\t' -v u="{${UPGRADE_CODE_AMD64^^}}" 'NR>3 && $1==u && $3=="'"$mv"'" && $5==1 && $7=="WIX_UPGRADE_DETECTED"' <(idt Upgrade) | wc -l)" = 1 ] || die "Upgrade table has no major-upgrade row"
  [ "$(awk -F'\t' -v u="{${UPGRADE_CODE_AMD64^^}}" 'NR>3 && $1==u && $2=="'"$mv"'" && $5==2 && $7=="WIX_DOWNGRADE_DETECTED"' <(idt Upgrade) | wc -l)" = 1 ] || die "Upgrade table has no downgrade-block row"
  [ "$(idt LaunchCondition | awk -F'\t' 'NR>3 && $1 == "NOT WIX_DOWNGRADE_DETECTED"' | wc -l)" -ge 1 ] || die "no launch condition blocks a downgrade"
  echo "ok: MajorUpgrade (upgrade row, downgrade block row, launch condition)"
  # custom actions: deferred (1024), not impersonated (2048); exe-from-file (18) or exe-from-property (50)
  want_ca() { # id type-without-ignore target-fragment
    local type target; type="$(col CustomAction "$1" 2)"; target="$(col CustomAction "$1" 4)"
    case "$type" in "$2"|"$(( $2 + 64 ))") ;; *) die "custom action $1 has type '$type', want $2 (+64 when its result is ignored)" ;; esac
    case "$target" in *"$3"*) ;; *) die "custom action $1 target '$target' lacks '$3'" ;; esac
  }
  want_ca RivetSetupStamped 3090 'setup --silent --from-msi --payload-from "[OriginalDatabase]"'
  want_ca RivetSetupProps 3090 'setup --silent --from-msi --server "[SERVER]" --token "[ENROLL_TOKEN]"'
  want_ca RivetUninstall 3090 'uninstall --from-msi'
  want_ca RivetUninstallMesh 3090 'uninstall --from-msi --remove-meshagent'
  want_ca RivetStopServiceInstall 3122 'stop RivetITAgent'
  want_ca RivetStopServiceRemove 3122 'stop RivetITAgent'
  [ "$(col CustomAction RivetSetupStamped 3)" = AgentExeFile ] || die "RivetSetupStamped does not run the installed exe"
  [ "$(col CustomAction RivetSetupStamped 2)" = 3090 ] || die "a failed setup must fail the install (result not ignored)"
  [ "$(col CustomAction RivetSetNetExe 2)" = 51 ] && [ "$(col CustomAction RivetSetNetExe 4)" = '[SystemFolder]net.exe' ] || die "RivetSetNetExe"
  echo "ok: custom actions RivetSetupStamped RivetSetupProps RivetUninstall RivetUninstallMesh RivetStopService* (deferred, SYSTEM, right command lines)"
  # sequencing: every deferred action lies between InstallInitialize and InstallFinalize, in the order the install needs
  sq() { col InstallExecuteSequence "$1" 3; }
  local ii fin f rf pre
  ii="$(sq InstallInitialize)"; fin="$(sq InstallFinalize)"; f="$(sq InstallFiles)"; rf="$(sq RemoveFiles)"; pre="$(sq RivetSetNetExe)"
  [ "$pre" -lt "$ii" ] || die "RivetSetNetExe must run before InstallInitialize"
  for a in RivetStopServiceInstall RivetStopServiceRemove RivetSetupStamped RivetSetupProps RivetUninstall RivetUninstallMesh; do
    s="$(sq "$a")"; { [ -n "$s" ] && [ "$s" -gt "$ii" ] && [ "$s" -lt "$fin" ]; } || die "$a (sequence '$s') is not between InstallInitialize ($ii) and InstallFinalize ($fin)"
  done
  [ "$(sq RivetSetupStamped)" -gt "$f" ] && [ "$(sq RivetSetupProps)" -gt "$f" ] || die "setup must run after InstallFiles"
  [ "$(sq RivetStopServiceInstall)" -lt "$f" ] || die "the service must be stopped before InstallFiles"
  [ "$(sq RivetUninstall)" -lt "$rf" ] && [ "$(sq RivetUninstallMesh)" -lt "$rf" ] && [ "$(sq RivetStopServiceRemove)" -lt "$(sq RivetUninstall)" ] || die "uninstall actions must run before RemoveFiles, after the service stop"
  echo "ok: sequence (stop < InstallFiles < setup; stop < uninstall < RemoveFiles; all inside InstallInitialize..InstallFinalize)"
  cond() { col InstallExecuteSequence "$1" 2; }
  case "$(cond RivetUninstall)" in *'NOT UPGRADINGPRODUCTCODE'*) ;; *) die "RivetUninstall would run during an upgrade" ;; esac
  case "$(cond RivetUninstallMesh)" in *'REMOVE_MESHAGENT="1"'*) ;; *) die "the Mesh agent is removed without REMOVE_MESHAGENT=1" ;; esac
  case "$(cond RivetUninstall)" in *'NOT REMOVE_MESHAGENT="1"'*) ;; *) die "RivetUninstall does not exclude REMOVE_MESHAGENT=1" ;; esac
  case "$(cond RivetSetupStamped)" in *'NOT Installed'*'NOT SERVER'*) ;; *) die "RivetSetupStamped condition" ;; esac
  case "$(cond RivetSetupProps)" in *'NOT Installed'*'AND SERVER'*) ;; *) die "RivetSetupProps condition" ;; esac
  echo "ok: conditions (uninstall not during an upgrade; Mesh untouched unless REMOVE_MESHAGENT=1; stamped xor properties)"
  [ "$(msiinfo suminfo "$msi" | sed -n 's/^Template: //p')" = 'x64;1033' ] || die "summary Template is not x64;1033"
  echo "ok: summary information Template x64;1033"
  if [ -n "$exe" ]; then
    local x absmsi; x="$(mktemp -d)"; absmsi="$(realpath "$msi")"
    (cd "$x" && msiextract "$absmsi" >/dev/null 2>&1) || die "msiextract cannot unpack the cabinet"
    local got; got="$(find "$x" -name rivetit-agent.exe | head -1)"
    [ -n "$got" ] && [ "$(sha256sum "$got" | cut -d' ' -f1)" = "$(sha256sum "$exe" | cut -d' ' -f1)" ] || { rm -rf "$x"; die "the exe inside the cabinet differs from the input"; }
    rm -rf "$x"
    echo "ok: the cabinet holds the agent exe byte for byte ($(sha256sum "$exe" | cut -c1-16)...)"
  fi
  rm -rf "$d"
}

build() {
  local version="$1" exe="$2" out="$3" mv pc pk sha
  [ -f "$exe" ] || die "$exe not found"
  [ "$(wixl --version | awk '{print $NF}')" = 0.103 ] || die "unexpected wixl version: $(wixl --version)"
  head -c 2 "$exe" | grep -q MZ || die "$exe is not a Windows executable"
  mv="$(msi_version "$version")"
  sha="$(sha256sum "$exe" | cut -d' ' -f1)"
  pc="$(guid "rivetit-agent-msi|productcode|amd64|$version")"
  pk="$(guid "rivetit-agent-msi|packagecode|amd64|$version|$sha")"
  work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
  cp "$exe" "$work/rivetit-agent.exe"; cp "$HERE/rivetit-agent.wxs" "$work/"
  touch -d "@$SDE" "$work/rivetit-agent.exe" "$work/rivetit-agent.wxs"
  ( cd "$work"
    out_w="$(faketime -f "$FAKETIME" wixl -a x64 \
      -D "Version=$mv" -D "ProductCode=${pc^^}" -D "PackageCode=${pk^^}" -D "UpgradeCode=${UPGRADE_CODE_AMD64^^}" -D "ComponentGuid=${COMPONENT_GUID_AMD64^^}" \
      -D ExePath=rivetit-agent.exe -D ProgramFilesDir=ProgramFiles64Folder -o built.msi rivetit-agent.wxs 2>&1)" || { echo "$out_w" >&2; die "wixl failed"; }
    # wixl reports an element or attribute it does not support as a GLib CRITICAL or WARNING and builds anyway: that is a failed build here.
    if printf '%s' "$out_w" | grep -q -i -E 'critical|warning|error'; then echo "$out_w" >&2; die "wixl reported problems (an unsupported attribute?)"; fi
    # The Package Id is ignored by wixl 0.103 (it makes a random revision number): set the summary information, with the derived package code, afterwards.
    faketime -f "$FAKETIME" msibuild built.msi -s 'RivetIT endpoint agent' 'RivetIT' 'x64;1033' "{${pk^^}}" >/dev/null
  )
  mkdir -p "$(dirname "$out")"
  cp "$work/built.msi" "$out"
  echo "built $out ($(stat -c %s "$out") bytes) version $version -> MSI $mv, ProductCode {${pc^^}}, PackageCode {${pk^^}}"
  check "$version" "$out" "$exe"
  echo "tools: $(tr '\n' ' ' < /etc/rivetit-msi-tools.txt 2>/dev/null)"
}

case "${1:-}" in
  build) [ $# -eq 4 ] || die "usage: build.sh build <version> <agent.exe> <out.msi>"; build "$2" "$3" "$4" ;;
  check) [ $# -ge 3 ] || die "usage: build.sh check <version> <file.msi> [agent.exe]"; check "$2" "$3" "${4:-}" ;;
  *) die "usage: build.sh build|check ..." ;;
esac

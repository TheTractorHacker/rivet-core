# Build image of the RivetIT Agent MSI template (packaging/msi/build.sh). Nothing here is Windows: msitools' wixl compiles the .wxs on Linux.
# Base pinned by digest; the tools pinned to the Ubuntu 24.04 release-pocket versions (they stay installable). faketime fixes the clock so the
# summary-information timestamps and the cabinet's times are constant, which is what makes a second build byte-identical.
FROM ubuntu:24.04@sha256:534baea6a22c03a63003dbc8dbe78fe34bc0d7e595d9a9dc9834884ff530eb55
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      wixl=0.103+repack-1build3 wixl-data=0.103+repack-1build3 msitools=0.103+repack-1build3 libmsi-1.0-0=0.103+repack-1build3 \
      faketime=0.9.10-2.1 libfaketime=0.9.10-2.1 libgcab-1.0-0=1.6-1build2 \
 && rm -rf /var/lib/apt/lists/* \
 && dpkg -l wixl msitools faketime libgcab-1.0-0 libgsf-1-114 | awk '/^ii/ {print $2 "=" $3}' > /etc/rivetit-msi-tools.txt

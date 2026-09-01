PLUGIN_NAME=		kixdns-community
PLUGIN_VERSION=		0.3
PLUGIN_COMMENT=		KixDNS High Performance DNS Server
PLUGIN_MAINTAINER=	Quan-0505@users.noreply.github.com
PLUGIN_WWW=		https://github.com/Quan-0505/opn-kixdns
PLUGIN_LICENSE=		GPLv3

# This Makefile is only useful when the directory is placed inside a checkout of
# https://github.com/opnsense/plugins (e.g. plugins/dns/kixdns-community) and the
# FreeBSD kixdns binary has been staged as src/usr/local/bin/kixdns beforehand:
#
#   make package        # produces work/pkg/os-kixdns-community-0.3.pkg
#
# The GitHub Actions workflow in .github/workflows/build-opnsense.yml builds the
# same layout without a FreeBSD machine.
.include "../../Mk/plugins.mk"

PLUGIN_NAME=		kixdns-community
PLUGIN_VERSION=		0.2
PLUGIN_COMMENT=		KixDNS High Performance DNS Server
PLUGIN_MAINTAINER=	troubadour-hell@users.noreply.github.com
PLUGIN_WWW=		https://github.com/troubadour-hell/opn-kixdns
PLUGIN_LICENSE=		GPLv3

# This Makefile is only useful when the directory is placed inside a checkout of
# https://github.com/opnsense/plugins (e.g. plugins/dns/kixdns-community) and the
# FreeBSD kixdns binary has been staged as src/usr/local/bin/kixdns beforehand:
#
#   make package        # produces work/pkg/os-kixdns-community-0.2.pkg
#
# The GitHub Actions workflow in .github/workflows/build-opnsense.yml builds the
# same layout without a FreeBSD machine.
.include "../../Mk/plugins.mk"

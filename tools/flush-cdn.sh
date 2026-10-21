#!/usr/bin/env bash
# Flushes Hostinger's CDN cache for the site through the Hostinger API.
#   tools/flush-cdn.sh                    flush now
#   tools/flush-cdn.sh --wait-for BUILD   first wait (up to 10 minutes) until https://$SITE_DOMAIN/health reports BUILD,
#                                         so the CDN refills with the new site, never the old one
# Needs HOSTINGER_API_TOKEN (hPanel → Profile → API), HOSTINGER_USERNAME (the hosting account, u…) and SITE_DOMAIN.
# Without a token it says so and does nothing, so a deploy never fails for want of one.
set -euo pipefail

domain="${SITE_DOMAIN:?SITE_DOMAIN is not set}"
user="${HOSTINGER_USERNAME:?HOSTINGER_USERNAME is not set}"
if [ -z "${HOSTINGER_API_TOKEN:-}" ]; then
  echo "::warning::HOSTINGER_API_TOKEN is not set, so the CDN was not flushed (docs/DEPLOY.md, After each deploy)."
  exit 0
fi

if [ "${1:-}" = "--wait-for" ]; then
  want="${2:?--wait-for needs a build}"
  got=''
  for _ in $(seq 1 60); do
    # A query string of its own keeps any cache out of the answer.
    got="$(curl -fsS --max-time 15 "https://$domain/health?build=$want.$RANDOM" 2>/dev/null | sed -n 's/.*"build":"\([^"]*\)".*/\1/p' || true)"
    [ "$got" = "$want" ] && break
    sleep 10
  done
  if [ "$got" = "$want" ]; then
    echo "The site serves build $want."
  else
    echo "::warning::After 10 minutes the site reports build '${got:-unknown}', not $want: Hostinger's Git deployment may not have run. Flushing anyway."
  fi
fi

status="$(curl -sS -o /tmp/flush-cdn.json -w '%{http_code}' -X DELETE \
  -H "Authorization: Bearer $HOSTINGER_API_TOKEN" -H 'Accept: application/json' \
  "https://developers.hostinger.com/api/hosting/v1/accounts/$user/websites/$domain/cdn/purge")"
if [ "$status" -ge 200 ] && [ "$status" -lt 300 ]; then
  echo "CDN flushed for $domain."
else
  echo "::error::The Hostinger API answered $status when flushing the CDN: $(head -c 300 /tmp/flush-cdn.json)"
  exit 1
fi

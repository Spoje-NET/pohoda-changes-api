# Run pohoda-changes poller every 2 minutes (flock skips overlap)
*/2 * * * * root [ -x /usr/bin/pohoda-changes-poller ] && /usr/bin/flock -n /run/pohoda-changes-poller.lock /usr/bin/pohoda-changes-poller >/dev/null 2>&1

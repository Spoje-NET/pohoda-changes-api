# Run pohoda-changes poller every 2 minutes
*/2 * * * * root [ -x /usr/share/pohoda-changes-api/bin/pohoda-changes-poller ] && /usr/share/pohoda-changes-api/bin/pohoda-changes-poller >/dev/null 2>&1

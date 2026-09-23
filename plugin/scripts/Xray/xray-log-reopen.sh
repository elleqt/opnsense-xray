#!/bin/sh
# Вызывается newsyslog после ротации /var/log/xray-core*.log (флаг R в
# /etc/newsyslog.conf.d/xray.conf). Шлёт SIGHUP супервизорам daemon(8),
# запущенным с -H -o: по SIGHUP они закрывают старый лог и открывают новый файл.
#
# Pidfile супервизора (-P /var/run/xray-daemon-*.pid) есть только у демонов,
# запущенных с -H, поэтому старые демоны без -H, для которых SIGHUP означал бы
# завершение, сюда не попадают. Процесс сверяется по имени: pidfile мог
# пережить убитый демон, а его pid достаться чужому процессу.

for f in /var/run/xray-daemon-*.pid; do
    [ -f "$f" ] || continue
    pid=$(cat "$f" 2>/dev/null)
    case "$pid" in
        ''|*[!0-9]*) continue ;;
    esac
    [ "$(ps -o comm= -p "$pid" 2>/dev/null)" = "daemon" ] || continue
    kill -HUP "$pid" 2>/dev/null
done
exit 0

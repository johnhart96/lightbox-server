FROM alpine:3.19
RUN apk add --no-cache syslog-ng
COPY syslog-ng.conf /etc/syslog-ng/syslog-ng.conf
RUN mkdir -p /data/syslog
CMD ["syslog-ng", "--no-caps", "-F", "-f", "/etc/syslog-ng/syslog-ng.conf"]

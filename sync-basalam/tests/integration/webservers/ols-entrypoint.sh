#!/bin/sh
set -eu
# Keep the vendor's CGI daemon/resource configuration; change only its example vhost.
sed -E -i 's@vhRoot[[:space:]]+Example/@vhRoot /var/www/html/@; s@restrained[[:space:]]+1@restrained 0@' /usr/local/lsws/conf/httpd_config.conf
sed "s/@MATRIX_TOKEN@/$MATRIX_TOKEN/g" /matrix/ols-vhost.conf > /usr/local/lsws/conf/vhosts/Example/vhconf.conf
exec /entrypoint.sh

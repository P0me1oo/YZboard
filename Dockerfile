FROM phpswoole/swoole:php8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# 逐个安装 PHP 扩展，并降低部分扩展的优化级别以兼容 ARM64 构建。
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip && \
    install-php-extensions redis && \
    apk --no-cache add shadow sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis caddy && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY .docker /

# 发布构建必须传入完整 commit，禁止在镜像内跟随移动分支。
ARG CACHEBUST=1
ARG REPO_URL=https://github.com/P0me1oo/YZboard.git
ARG SOURCE_COMMIT=""

RUN test -n "${SOURCE_COMMIT}" && \
    echo "Fetching commit ${SOURCE_COMMIT} from ${REPO_URL} with CACHEBUST=${CACHEBUST}" && \
    find /www -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + && \
    git config --global --add safe.directory /www && \
    git init . && \
    git remote add origin "${REPO_URL}" && \
    git fetch --depth 1 origin "${SOURCE_COMMIT}" && \
    git checkout --detach FETCH_HEAD && \
    test "$(git rev-parse HEAD)" = "${SOURCE_COMMIT}" && \
    git submodule update --init --recursive --force

# 管理端是构建产物，节点编辑表单的「父级节点」下拉按同协议过滤，选不到跨协议的
# VLESS 前置入口。这里定点注入独立的前置入口选择框和节点列表的前置入口列；
# 锚点匹配不到会直接失败，避免静默产出缺少这些界面的管理端。
RUN php /www/.docker/patch-admin-relay.php /www/public/assets/admin/assets

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

RUN composer install --no-cache --no-dev --no-security-blocking \
    && php artisan storage:link \
    && chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data
    
ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true

EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"] 

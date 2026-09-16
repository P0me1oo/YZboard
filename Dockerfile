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

# 管理端产物随源码一起进入镜像：public/assets/admin 由 YZboard-Dash 源码工程构建后
# 同步进仓库，已内置全部 YZ 定制（节点前置入口与内核、单节点运行开关、内部端口校验、
# 节点批量权限组、插件上传 64 MiB、套餐周期价格、管理员两步验证）。
# 该目录此前是指向上游 xboard-admin-dist 的子模块，需要在构建期用字符串补丁注入定制；
# 现已改为仓库内产物，因此不再执行 submodule 更新，也不再有 .docker/patch-admin-*.php。
RUN test -n "${SOURCE_COMMIT}" && \
    echo "Fetching commit ${SOURCE_COMMIT} from ${REPO_URL} with CACHEBUST=${CACHEBUST}" && \
    find /www -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + && \
    git config --global --add safe.directory /www && \
    git init . && \
    git remote add origin "${REPO_URL}" && \
    git fetch --depth 1 origin "${SOURCE_COMMIT}" && \
    git checkout --detach FETCH_HEAD && \
    test "$(git rev-parse HEAD)" = "${SOURCE_COMMIT}"

# 缺少管理端产物会让后台白屏，这里在构建期显式失败而不是产出坏镜像。
RUN test -f /www/public/assets/admin/manifest.json && \
    test -n "$(ls /www/public/assets/admin/locales/*.js 2>/dev/null)"

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

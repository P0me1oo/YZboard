/* yz_admin_request_error */
function yzAdminRequestError(error) {
    const status = error?.response?.status;
    const payload = error?.response?.data;
    const body = payload && typeof payload === "object" && !Array.isArray(payload) ? payload : null;
    const text = value => typeof value === "string" ? value.trim() : "";
    const validation = body?.errors && typeof body.errors === "object"
        ? Object.values(body.errors).flat().map(text).filter(Boolean).join("；") : "";
    // 网关可能返回纯文本或整页 HTML；HTML 使用状态码提示，避免把错误页当成消息。
    const responseText = typeof payload === "string" && !/<[a-z!][^>]*>/i.test(payload) ? text(payload) : "";
    const serverMessage = text(body?.message) || validation || text(body?.error?.message)
        || text(body?.error) || responseText;
    const statusMessages = {
        400: "请求参数有误，请检查后重试",
        401: lL.t("common:http.loginExpired"),
        403: lL.t("common:http.noPermission"),
        404: lL.t("common:http.notFound"),
        408: "服务器接收请求超时（HTTP 408），请检查网络后重试",
        413: "上传文件过大，请检查服务器上传限制",
        419: "页面会话已过期，请刷新页面后重试",
        422: "提交内容校验失败，请检查后重试",
        429: "请求过于频繁，请稍后重试",
        500: "服务器内部错误（HTTP 500），请查看服务端日志",
        502: "网关连接服务异常（HTTP 502），请检查服务状态",
        503: "服务暂时不可用（HTTP 503），请稍后重试",
        504: "网关等待服务器响应超时（HTTP 504），请检查网关和服务端超时设置",
        524: "网关与服务器通信超时（HTTP 524），请检查服务端处理耗时",
    };

    let message = serverMessage || statusMessages[status];
    if (!message && status) {
        message = `请求失败（HTTP ${status}）`;
    }
    if (!message) {
        // Axios 的 ECONNABORTED 同时用于超时和原生中断，避免把中断误报为等待超时。
        const timeout = error?.code === "ETIMEDOUT"
            || (error?.code === "ECONNABORTED" && !/^request aborted$/i.test(text(error?.message)));
        if (timeout) {
            const milliseconds = Number(error?.config?.timeout);
            const duration = Number.isFinite(milliseconds) && milliseconds > 0
                ? (milliseconds % 60000 === 0 ? `${milliseconds / 60000} 分钟` : `${Math.ceil(milliseconds / 1000)} 秒`)
                : "";
            message = text(error?.config?.timeoutErrorMessage)
                || `请求超时${duration ? `（${duration}）` : ""}，请检查网络或服务状态后重试`;
        } else if (error?.code === "ERR_NETWORK") {
            message = "网络连接失败，未收到服务器响应，请检查网络或服务状态";
        } else if (error?.code === "ERR_CANCELED") {
            message = "请求已取消";
        } else if (error?.code === "ECONNABORTED") {
            message = "请求已中断，请重试";
        } else {
            message = text(error?.message) || lL.t("common:http.unknownError");
        }
    }

    if (status === 401 || status === 403) wE();
    // 插件上传页面自行显示失败原因；其他请求继续使用全局提示。
    if (!error?.config?.skipErrorToast) gE.error(message);
    return Promise.reject(body?.message === message ? body : {
        ...(body || { data: null }),
        code: body?.code ?? status ?? error?.code ?? -1,
        message,
    });
}

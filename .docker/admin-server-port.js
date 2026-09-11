/* yz_server_port_validation:start */
// 只发送判断监听占用需要的字段，协议认证和证书内容不参与预检查。
function yzServerPortPayload(values, type) {
    const settings = values.protocol_settings || {};
    return {
        id: values.id ?? null,
        machine_id: values.machine_id ?? null,
        server_port: String(values.server_port ?? "").trim(),
        type: type,
        kernel_type: values.kernel_type ?? null,
        enabled: values.enabled ?? null,
        protocol_settings: {
            network: settings.network,
            transport: settings.transport,
            tls: typeof settings.tls === "number" ? settings.tls : undefined
        }
    };
}

function yzCreateServerPortValidator(form, context, request) {
    let sequence = 0;
    const errorType = "yz_server_port_validation";
    request = request || (payload => RL(UL + "/server/manage/checkPort", payload));

    function clear() {
        if (form.getFieldState("server_port").error?.type === errorType) {
            form.clearErrors("server_port");
        }
    }

    function report(message, focus) {
        if (message) {
            form.setError("server_port", { type: errorType, message: message }, { shouldFocus: focus });
        } else {
            clear();
        }
    }

    function signature() {
        return JSON.stringify(yzServerPortPayload(form.getValues(), context().type));
    }

    return {
        cancel() {
            sequence++;
            clear();
        },
        async validate(submitting = false) {
            const current = context();
            const payload = yzServerPortPayload(form.getValues(), current.type);
            const key = JSON.stringify(payload);
            const attempt = ++sequence;
            clear();
            if (!current.open || !current.type) return false;
            if (!/^[1-9]\d{0,4}$/.test(payload.server_port) || Number(payload.server_port) > 65535) {
                if (payload.server_port || submitting) {
                    report(payload.server_port ? "内部端口必须是 1 到 65535 之间的整数" : "内部端口不能为空", submitting);
                }
                return false;
            }
            if (!payload.machine_id) return true;

            // 切换协议、端口或服务器后，迟到的响应不能覆盖当前输入的检查结果。
            const isCurrent = () => attempt === sequence && context().open && signature() === key;
            try {
                const response = await request(payload);
                if (!isCurrent()) return false;
                const result = response?.data;
                if (typeof result?.valid !== "boolean") {
                    throw new Error("内部端口检查响应无效");
                }
                report(result.valid ? null : result.message || "内部端口已被占用，请更换端口", submitting);
                return result.valid;
            } catch (error) {
                if (isCurrent()) {
                    report(error?.errors?.server_port?.[0] || "内部端口检查失败，请重试", submitting);
                }
                return false;
            }
        },
        reportSaveError(error, submitted) {
            if (context().open && signature() === JSON.stringify(yzServerPortPayload(submitted, context().type))) {
                const message = error?.errors?.server_port?.[0];
                if (message) report(message, true);
            }
        }
    };
}

function yzUseServerPortValidation(form, open, type) {
    const fields = ["id", "machine_id", "server_port", "kernel_type", "enabled", "protocol_settings"];
    const watched = I_({ control: form.control, name: fields });
    const values = Object.fromEntries(fields.map((field, index) => [field, watched[index]]));
    const key = JSON.stringify(yzServerPortPayload(values, type));
    const context = H.useRef({ open: open, type: type });
    context.current = { open: open, type: type };
    const validator = H.useMemo(() => yzCreateServerPortValidator(form, () => context.current), [form]);

    H.useEffect(() => {
        validator.cancel();
        if (!open) return;
        const timer = setTimeout(() => { void validator.validate(); }, 300);
        return () => {
            clearTimeout(timer);
            validator.cancel();
        };
    }, [open, key, validator]);

    return validator;
}
/* yz_server_port_validation:end */

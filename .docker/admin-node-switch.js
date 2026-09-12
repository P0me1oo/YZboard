/* yz_node_runtime_switch:start */
const yzNodeSwitchMessages = {
    zh: {
        title: "开关",
        turnOn: "开启节点",
        turnOff: "关闭节点",
        standalone: "独立部署节点需在部署端启停",
        failed: "节点开关更新失败，请重试",
        refreshFailed: "节点状态已更新，请刷新列表确认"
    },
    en: {
        title: "On / off",
        turnOn: "Start node",
        turnOff: "Stop node",
        standalone: "Manage standalone nodes on their deployment server",
        failed: "Failed to update the node. Please try again.",
        refreshFailed: "Node updated. Refresh the list to confirm its state."
    },
    ru: {
        title: "Вкл. / выкл.",
        turnOn: "Запустить узел",
        turnOff: "Остановить узел",
        standalone: "Управляйте отдельным узлом на сервере его размещения",
        failed: "Не удалось изменить состояние узла. Повторите попытку.",
        refreshFailed: "Состояние узла изменено. Обновите список."
    }
};

// 表头沿用原表格的 title 属性，让移动端卡片也能读到相同文案。
for (const [language, key] of Object.entries({ "zh-CN": "zh", "en-US": "en", "ru-RU": "ru" })) {
    lL.addResource(language, "server", "columns.enabled", yzNodeSwitchMessages[key].title);
}

function useYzNodeSwitchText() {
    const { i18n } = jy("server");
    const language = (i18n.resolvedLanguage || i18n.language || "zh-CN").split("-")[0];
    return yzNodeSwitchMessages[language] || yzNodeSwitchMessages.zh;
}

function F5t({ node, refetch }) {
    const text = useYzNodeSwitchText();
    const [pending, setPending] = H.useState(false);
    const busy = H.useRef(false);
    const managed = Number(node.machine_id) > 0;
    const enabled = Boolean(node.enabled);

    // 独立部署没有按机器发现节点的启停通道，不能用显隐状态冒充运行状态。
    if (!managed) {
        return Q.jsx("span", {
            className: "text-muted-foreground",
            title: text.standalone,
            "aria-label": text.standalone,
            children: "--"
        });
    }

    return Q.jsx(oZt, {
        checked: enabled,
        disabled: pending,
        "aria-busy": pending,
        "aria-label": (enabled ? text.turnOff : text.turnOn) + ": " + node.name,
        "data-yz-node-switch": node.id,
        onCheckedChange: async next => {
            // 引用立即加锁，阻止组件重新渲染前的重复点击。
            if (busy.current || next === enabled) return;
            busy.current = true;
            setPending(true);
            let saved = false;
            try {
                // 只提交当前节点编号及启用状态，不调用整台服务器的停用接口。
                const response = await XL({ id: node.id, enabled: next });
                if (response?.data !== true) throw new Error(text.failed);
                saved = true;
                const refreshed = await refetch();
                if (refreshed?.isError) throw new Error(text.refreshFailed);
            } catch (error) {
                gE.error(saved
                    ? text.refreshFailed
                    : error?.errors?.server_port?.[0] || error?.message || text.failed);
            } finally {
                busy.current = false;
                setPending(false);
            }
        },
        style: { backgroundColor: enabled ? sqt[node.type] : undefined }
    });
}
/* yz_node_runtime_switch:end */

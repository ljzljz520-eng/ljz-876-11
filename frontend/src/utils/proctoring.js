// 监考事件类型与状态的前端映射（与后端 ProctoringEvent::TYPES 对齐）
export const EVENT_TYPES = {
  tab_switch: { label: '切屏/窗口失焦', severity: 'warning', penalty: 2 },
  fullscreen_exit: { label: '退出全屏', severity: 'warning', penalty: 2 },
  camera_off: { label: '摄像头断开', severity: 'critical', penalty: 3 },
  camera_on: { label: '摄像头恢复', severity: 'info', penalty: 0 },
  idle: { label: '长时间无操作', severity: 'warning', penalty: 2 },
  network_offline: { label: '网络中断', severity: 'warning', penalty: 0 },
  network_online: { label: '网络恢复', severity: 'info', penalty: 0 },
  manual_flag: { label: '监考员标记', severity: 'critical', penalty: 5 }
}

export const SEVERITY_STYLE = {
  info: { dot: 'bg-sky-500', badge: 'bg-sky-100 text-sky-700', label: '提示' },
  warning: { dot: 'bg-amber-500', badge: 'bg-amber-100 text-amber-700', label: '警告' },
  critical: { dot: 'bg-red-500', badge: 'bg-red-100 text-red-700', label: '严重' }
}

export const EVENT_STATUS_STYLE = {
  pending: { label: '待复核', cls: 'bg-amber-100 text-amber-700' },
  confirmed: { label: '违规成立', cls: 'bg-red-100 text-red-700' },
  dismissed: { label: '已撤销', cls: 'bg-gray-100 text-gray-500' }
}

export const APPEAL_STATUS_STYLE = {
  pending: { label: '待复核', cls: 'bg-amber-100 text-amber-700' },
  approved: { label: '申诉成立', cls: 'bg-green-100 text-green-700' },
  rejected: { label: '申诉驳回', cls: 'bg-red-100 text-red-700' }
}

export const formatDateTime = (s) => {
  if (!s) return '-'
  return new Date(s).toLocaleString('zh-CN', { hour12: false })
}

export const formatDuration = (sec) => {
  sec = Number(sec) || 0
  if (sec < 60) return `${sec} 秒`
  const m = Math.floor(sec / 60)
  const r = sec % 60
  return r ? `${m} 分 ${r} 秒` : `${m} 分钟`
}

import { ref } from 'vue'
import api from '../api'

/**
 * 考试过程监考事件采集
 * 采集：切屏/窗口失焦、摄像头断开/恢复、长时间无操作、网络中断/恢复、退出全屏
 * 事件先入队，定时批量上报；页面卸载时用 keepalive 补发。
 */
export function useProctoring(examRecordId) {
  const queue = []
  const idleLimitMs = 60 * 1000 // 连续无操作阈值：60 秒
  let lastActiveAt = Date.now()
  let idleFired = false
  let flushTimer = null
  let idleTimer = null
  let stopped = false
  let stream = null
  let wasFullscreen = false
  let lastFocusHiddenAt = 0

  const cameraState = ref('unknown') // unknown | on | off | denied
  const networkState = ref(navigator.onLine ? 'online' : 'offline')
  const activeAnomalyCount = ref(0)
  const lastEventAt = ref(null)

  const record = (type, detail = null) => {
    if (stopped || !examRecordId.value) return
    queue.push({
      type,
      detail,
      occurred_at: new Date().toISOString()
    })
    lastEventAt.value = new Date()
    const anomalyTypes = ['tab_switch', 'camera_disconnected', 'idle_long', 'network_lost', 'fullscreen_exit']
    if (anomalyTypes.includes(type)) activeAnomalyCount.value++
  }

  const flush = async (useKeepalive = false) => {
    if (!queue.length || !examRecordId.value) return
    const events = queue.splice(0, queue.length)
    const payload = JSON.stringify({
      exam_record_id: examRecordId.value,
      events
    })

    if (useKeepalive) {
      // 页面卸载时 axios 可能被中止，用 fetch keepalive 补发
      const base = import.meta.env.VITE_API_BASE_URL || '/api'
      try {
        await fetch(`${base}/proctoring/events`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${localStorage.getItem('token') || ''}`
          },
          body: payload,
          keepalive: true
        })
      } catch (e) {
        // 静默失败，卸载阶段无法提示
      }
      return
    }

    try {
      await api.post('/proctoring/events', {
        exam_record_id: examRecordId.value,
        events
      })
    } catch (e) {
      // 上报失败放回队首，下次重试
      queue.unshift(...events)
    }
  }

  // ---------- 切屏 / 窗口焦点 ----------
  const onVisibility = () => {
    if (document.hidden) {
      lastFocusHiddenAt = Date.now()
      record('tab_switch', '考试页面被隐藏或切换到其他窗口/标签页')
    }
  }
  const onBlur = () => record('tab_switch', '考试窗口失去焦点')
  const onFocus = () => {
    if (lastFocusHiddenAt) {
      const secs = Math.round((Date.now() - lastFocusHiddenAt) / 1000)
      lastFocusHiddenAt = 0
      if (secs >= 3) {
        record('tab_switch', `切出考试页面约 ${secs} 秒后返回`)
      }
    }
  }

  // ---------- 长时间无操作 ----------
  const onActivity = () => {
    lastActiveAt = Date.now()
    idleFired = false
  }
  const startIdleCheck = () => {
    idleTimer = setInterval(() => {
      if (Date.now() - lastActiveAt >= idleLimitMs && !idleFired) {
        idleFired = true
        record('idle_long', `连续 ${Math.round(idleLimitMs / 1000)} 秒无鼠标/键盘操作`)
      }
    }, 10 * 1000)
  }

  // ---------- 网络 ----------
  const onOffline = () => {
    networkState.value = 'offline'
    record('network_lost', '网络连接中断，答题数据将在本地暂存')
  }
  const onOnline = () => {
    networkState.value = 'online'
    record('network_restored', '网络连接已恢复')
    flush()
  }

  // ---------- 全屏 ----------
  const onFullscreenChange = () => {
    const isFullscreen = !!document.fullscreenElement
    if (isFullscreen) {
      wasFullscreen = true
    } else if (wasFullscreen) {
      record('fullscreen_exit', '考试过程中退出了全屏模式')
    }
  }

  // ---------- 摄像头 ----------
  const bindCameraTrack = (track) => {
    cameraState.value = 'on'
    track.addEventListener('mute', () => {
      cameraState.value = 'off'
      record('camera_disconnected', '摄像头信号中断(设备被占用或断开)')
    })
    track.addEventListener('unmute', () => {
      cameraState.value = 'on'
      record('camera_restored', '摄像头信号已恢复')
    })
    track.addEventListener('ended', () => {
      cameraState.value = 'off'
      record('camera_disconnected', '摄像头设备已断开')
    })
  }

  const startCamera = async () => {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      cameraState.value = 'denied'
      record('camera_disconnected', '当前浏览器不支持摄像头采集')
      return
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { width: 320, height: 240 },
        audio: false
      })
      stream.getVideoTracks().forEach(bindCameraTrack)
    } catch (e) {
      cameraState.value = 'denied'
      const reason = e?.name === 'NotAllowedError'
        ? '摄像头权限被拒绝'
        : `摄像头无法访问(${e?.name || '未知错误'})`
      record('camera_disconnected', reason)
    }
  }

  const start = async () => {
    record('monitor_start', '监考已启动')
    document.addEventListener('visibilitychange', onVisibility)
    window.addEventListener('blur', onBlur)
    window.addEventListener('focus', onFocus)
    window.addEventListener('offline', onOffline)
    window.addEventListener('online', onOnline)
    document.addEventListener('fullscreenchange', onFullscreenChange)
    ;['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach((evt) => {
      window.addEventListener(evt, onActivity, { passive: true })
    })

    startIdleCheck()
    flushTimer = setInterval(() => flush(false), 10 * 1000)
    window.addEventListener('pagehide', () => flush(true))

    await startCamera()
  }

  const stop = () => {
    stopped = true
    if (flushTimer) clearInterval(flushTimer)
    if (idleTimer) clearInterval(idleTimer)
    document.removeEventListener('visibilitychange', onVisibility)
    window.removeEventListener('blur', onBlur)
    window.removeEventListener('focus', onFocus)
    window.removeEventListener('offline', onOffline)
    window.removeEventListener('online', onOnline)
    document.removeEventListener('fullscreenchange', onFullscreenChange)
    if (stream) {
      stream.getTracks().forEach((t) => t.stop())
      stream = null
    }
  }

  return {
    cameraState,
    networkState,
    activeAnomalyCount,
    lastEventAt,
    start,
    stop,
    flush
  }
}

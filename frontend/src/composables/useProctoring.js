import { ref } from 'vue'
import api from '../api'
import { EVENT_TYPES } from '../utils/proctoring'

/**
 * 考试过程中的监考采集：
 *  - visibilitychange / pagehide  → 切屏
 *  - fullscreenchange             → 退出全屏
 *  - 摄像头 track ended / mute    → 摄像头断开 / 恢复
 *  - 鼠标键盘心跳                  → 长时间不操作
 *  - online / offline             → 网络中断与恢复（离线缓冲，恢复后补发）
 */
export function useProctoring(examRecordId, options = {}) {
  const IDLE_LIMIT = options.idleLimit ?? 45            // 秒，超过即记录长时间无操作
  const FLUSH_INTERVAL = options.flushInterval ?? 15000 // 定时批量上报
  const pending = []
  let flushedCount = 0
  let isOnline = navigator.onLine
  let stream = null

  const eventCount = ref(0)
  const online = ref(navigator.onLine)
  const cameraState = ref('pending') // pending | on | off | denied
  const lastEventLabel = ref('')

  const buildEvent = (type, extra = {}) => {
    const conf = EVENT_TYPES[type] || { severity: 'warning', penalty: 0 }
    return {
      event_type: type,
      severity: extra.severity || conf.severity,
      penalty: conf.penalty,
      occurred_at: new Date().toISOString(),
      duration: extra.duration || 0,
      detail: extra.detail || '',
      evidence_screenshot: extra.evidence_screenshot || null
    }
  }

  const buffer = (type, extra = {}) => {
    const evt = buildEvent(type, extra)
    pending.push(evt)
    eventCount.value = flushedCount + pending.length
    lastEventLabel.value = EVENT_TYPES[type]?.label || type
    // eslint-disable-next-line no-console
    console.warn('[监考事件]', EVENT_TYPES[type]?.label, evt.detail || '')
    if (isOnline && pending.length > 0) scheduleFlush()
  }

  let flushTimer = null
  function scheduleFlush() {
    if (flushTimer) return
    flushTimer = setTimeout(flush, 2000)
  }

  const flush = async () => {
    if (flushTimer) { clearTimeout(flushTimer); flushTimer = null }
    if (!isOnline || pending.length === 0) return
    const batch = pending.splice(0, pending.length)
    try {
      await api.post('/proctoring/events', {
        exam_record_id: examRecordId,
        events: batch
      })
      flushedCount += batch.length
      eventCount.value = flushedCount
    } catch (e) {
      // 失败放回队首，等待下次重试（网络恢复后也会触发）
      pending.unshift(...batch)
      eventCount.value = flushedCount + pending.length
    }
  }

  // ---- 切屏 ----
  const onVisibility = () => {
    if (document.hidden) {
      buffer('tab_switch', { detail: '页面被隐藏或切换到其他窗口（visibilitychange=hidden）' })
    }
  }
  const onPageHide = () => buffer('tab_switch', { detail: '页面失焦/最小化（pagehide/blur）' })

  // ---- 全屏退出 ----
  const onFullscreen = () => {
    if (!document.fullscreenElement) {
      buffer('fullscreen_exit', { detail: '考生退出了全屏模式' })
    }
  }

  // ---- 长时间无操作 ----
  let lastActive = Date.now()
  let idleNotified = false
  const markActive = () => { lastActive = Date.now(); idleNotified = false }
  let idleTimer = null
  const startIdleWatch = () => {
    idleTimer = setInterval(() => {
      const idleSec = Math.floor((Date.now() - lastActive) / 1000)
      if (idleSec >= IDLE_LIMIT && !idleNotified) {
        idleNotified = true
        buffer('idle', { duration: idleSec, detail: `连续 ${idleSec} 秒无鼠标/键盘操作` })
      }
    }, 5000)
  }

  // ---- 网络 ----
  const onOffline = () => {
    isOnline = false
    online.value = false
    buffer('network_offline', { detail: '网络连接中断，事件已在本地缓冲' })
  }
  const onOnline = () => {
    isOnline = true
    online.value = true
    buffer('network_online', { detail: '网络恢复，自动补发离线期间事件' })
    flush()
  }

  // ---- 摄像头 ----
  const bindCamera = (mediaStream) => {
    stream = mediaStream
    cameraState.value = 'on'
    mediaStream.getVideoTracks().forEach((track) => {
      track.onended = () => {
        cameraState.value = 'off'
        buffer('camera_off', { detail: '摄像头意外断开（track ended），请检查设备' })
      }
      track.onmute = () => {
        cameraState.value = 'off'
        buffer('camera_off', { detail: '摄像头画面中断（track muted）' })
      }
      track.onunmute = () => {
        cameraState.value = 'on'
        buffer('camera_on', { detail: '摄像头画面恢复' })
      }
    })
  }

  const requestCamera = async () => {
    if (!navigator.mediaDevices?.getUserMedia) {
      cameraState.value = 'denied'
      return null
    }
    try {
      const mediaStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false })
      bindCamera(mediaStream)
      return mediaStream
    } catch (e) {
      cameraState.value = 'denied'
      buffer('camera_off', { detail: '无法访问摄像头：' + (e.message || '权限被拒绝或设备不可用') })
      return null
    }
  }

  const stopCamera = () => {
    stream?.getTracks().forEach((t) => { t.stop() })
    stream = null
  }

  const start = async () => {
    document.addEventListener('visibilitychange', onVisibility)
    window.addEventListener('pagehide', onPageHide)
    window.addEventListener('blur', onPageHide)
    document.addEventListener('fullscreenchange', onFullscreen)
    ;['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach((evt) =>
      window.addEventListener(evt, markActive, { passive: true })
    )
    window.addEventListener('offline', onOffline)
    window.addEventListener('online', onOnline)
    startIdleWatch()
    flushTimer = setInterval(flush, FLUSH_INTERVAL)
    return requestCamera()
  }

  const stop = async () => {
    document.removeEventListener('visibilitychange', onVisibility)
    window.removeEventListener('pagehide', onPageHide)
    window.removeEventListener('blur', onPageHide)
    document.removeEventListener('fullscreenchange', onFullscreen)
    ;['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach((evt) =>
      window.removeEventListener(evt, markActive)
    )
    window.removeEventListener('offline', onOffline)
    window.removeEventListener('online', onOnline)
    if (idleTimer) clearInterval(idleTimer)
    if (flushTimer) { clearInterval(flushTimer); flushTimer = null }
    stopCamera()
    // 交卷前最后一次同步补发
    await flush()
    return pending
  }

  return {
    eventCount,
    online,
    cameraState,
    lastEventLabel,
    start,
    stop,
    flush,
    buffer
  }
}

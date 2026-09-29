<template>
  <div class="space-y-6">
    <!-- 成绩构成 -->
    <div class="bg-white rounded-lg shadow p-5">
      <div class="flex flex-wrap items-center gap-x-8 gap-y-3">
        <div>
          <div class="text-xs text-gray-500 mb-1">原始得分（自动评分）</div>
          <div class="text-2xl font-bold text-gray-800">{{ data.exam_record.base_score }}</div>
        </div>
        <div class="text-3xl text-gray-300">−</div>
        <div>
          <div class="text-xs text-gray-500 mb-1">监考扣分</div>
          <div class="text-2xl font-bold" :class="Number(data.exam_record.deduction) > 0 ? 'text-red-600' : 'text-gray-400'">{{ data.exam_record.deduction }}</div>
        </div>
        <div class="text-3xl text-gray-300">=</div>
        <div>
          <div class="text-xs text-gray-500 mb-1">最终成绩</div>
          <div class="text-2xl font-bold text-indigo-600">{{ data.exam_record.score }}</div>
        </div>
        <div class="ml-auto text-right">
          <div class="text-xs text-gray-500 mb-1">已确认违规</div>
          <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold"
            :class="Number(data.exam_record.anomaly_count) > 0 ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'">
            {{ data.exam_record.anomaly_count }} 条
          </span>
        </div>
        <div v-if="data.exam_record.review_status === 'reviewed'" class="w-full text-xs text-indigo-600 bg-indigo-50 rounded px-3 py-2">
          该成绩已经教师复核改判{{ data.exam_record.reviewed_at ? '（' + formatDateTime(data.exam_record.reviewed_at) + '）' : '' }}
        </div>
      </div>
    </div>

    <!-- 时间线 -->
    <div class="bg-white rounded-lg shadow p-6">
      <h3 class="text-lg font-bold text-gray-900 mb-6 flex items-center">
        <span class="w-1.5 h-6 bg-indigo-500 rounded-full mr-3"></span>
        监考事件时间线（按发生时间排序）
      </h3>
      <div v-if="data.timeline.length === 0" class="text-center text-gray-400 py-8">本场考试未记录到监考事件</div>
      <ol v-else class="relative border-l-2 border-gray-100 ml-3 space-y-6">
        <li v-for="item in data.timeline" :key="item.id" class="ml-6">
          <span class="absolute -left-[9px] mt-1.5 w-4 h-4 rounded-full border-2 border-white shadow"
            :class="SEVERITY_STYLE[item.severity].dot"></span>
          <div class="bg-gray-50 rounded-lg p-4">
            <div class="flex flex-wrap items-center gap-2 mb-1">
              <span class="font-semibold text-gray-900">{{ item.type_label }}</span>
              <span class="px-2 py-0.5 rounded text-xs font-medium" :class="SEVERITY_STYLE[item.severity].badge">
                {{ SEVERITY_STYLE[item.severity].label }}
              </span>
              <span class="px-2 py-0.5 rounded text-xs font-medium" :class="EVENT_STATUS_STYLE[item.status].cls">
                {{ item.status_label }}
              </span>
              <span v-if="Number(item.penalty) > 0 && item.status === 'confirmed'" class="px-2 py-0.5 rounded text-xs font-medium bg-red-50 text-red-600">
                扣 {{ item.penalty }} 分
              </span>
              <span class="text-xs text-gray-400 ml-auto">{{ formatDateTime(item.occurred_at) }}</span>
            </div>
            <p v-if="item.detail" class="text-sm text-gray-600 mb-2">{{ item.detail }}</p>
            <p v-if="item.duration" class="text-xs text-gray-400 mb-2">持续：{{ formatDuration(item.duration) }}</p>
            <img v-if="item.evidence_screenshot" :src="item.evidence_screenshot" alt="监考截图"
              class="mt-2 max-h-48 rounded border border-gray-200 cursor-pointer" @click="preview = item.evidence_screenshot">

            <!-- 申诉信息 -->
            <div v-if="item.appeal" class="mt-3 border-l-4 border-amber-300 bg-amber-50 rounded p-3 text-sm">
              <div class="flex items-center gap-2 mb-1">
                <span class="font-medium text-amber-800">学生申诉</span>
                <span class="px-2 py-0.5 rounded text-xs font-medium" :class="APPEAL_STATUS_STYLE[item.appeal.status].cls">
                  {{ item.appeal.status_label }}
                </span>
              </div>
              <p class="text-gray-700">{{ item.appeal.reason }}</p>
              <img v-if="item.appeal.evidence_screenshot" :src="item.appeal.evidence_screenshot" alt="申诉截图"
                class="mt-2 max-h-48 rounded border border-amber-200 cursor-pointer" @click="preview = item.appeal.evidence_screenshot">
              <p v-if="item.appeal.review_note" class="mt-2 text-xs text-gray-500">
                教师复核意见（{{ formatDateTime(item.appeal.reviewed_at) }}）：{{ item.appeal.review_note }}
              </p>
            </div>

            <p v-if="item.review_note && !item.appeal" class="mt-2 text-xs text-gray-500">复核备注：{{ item.review_note }}</p>

            <div class="mt-3 flex gap-2">
              <slot name="actions" :item="item" />
            </div>
          </div>
        </li>
      </ol>
    </div>

    <!-- 截图大图预览 -->
    <div v-if="preview" class="fixed inset-0 z-[80] bg-black/70 flex items-center justify-center p-6" @click="preview = null">
      <img :src="preview" class="max-h-full max-w-full rounded-lg shadow-2xl">
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import {
  SEVERITY_STYLE,
  EVENT_STATUS_STYLE,
  APPEAL_STATUS_STYLE,
  formatDateTime,
  formatDuration
} from '../../utils/proctoring'

defineProps({
  data: { type: Object, required: true }
})

const preview = ref(null)
</script>

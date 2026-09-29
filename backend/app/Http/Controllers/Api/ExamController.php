<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\ProctoringEvent;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        $examPapers = ExamPaper::with('creator')
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'exam_papers' => $examPapers,
        ]);
    }

    public function start(Request $request, ExamPaper $examPaper)
    {
        $existingRecord = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->first();

        if ($existingRecord) {
            return response()->json([
                'message' => '您已经开始这场考试',
                'exam_record' => $existingRecord,
            ]);
        }

        $record = ExamRecord::create([
            'user_id' => $request->user()->id,
            'exam_paper_id' => $examPaper->id,
            'start_time' => now(),
            'status' => 'in_progress',
        ]);

        $questions = $examPaper->questions()->get();

        $questionsData = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => $q->pivot->score,
            ];
        });

        return response()->json([
            'message' => '考试开始',
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
            ],
            'questions' => $questionsData,
        ]);
    }

    public function getQuestions(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        $questions = $examPaper->questions()->get();

        $questionsData = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => $q->pivot->score,
            ];
        });

        return response()->json([
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
            ],
            'questions' => $questionsData,
        ]);
    }

    public function submit(Request $request, ExamPaper $examPaper)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => 'required|string',
            'events' => 'sometimes|array',
            'events.*.event_type' => 'required|string|max:30',
            'events.*.severity' => 'sometimes|string|in:info,warning,critical',
            'events.*.occurred_at' => 'sometimes|date',
            'events.*.duration' => 'sometimes|integer|min:0',
            'events.*.detail' => 'sometimes|nullable|string|max:500',
            'events.*.evidence_screenshot' => 'sometimes|nullable|string|max:2000000',
            'events.*.penalty' => 'sometimes|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        $totalScore = 0;
        $questionMap = $examPaper->questions->keyBy('id');

        DB::transaction(function () use ($request, $examPaper, $record, $questionMap, &$totalScore) {
            foreach ($request->answers as $answerData) {
                $question = $questionMap->get($answerData['question_id']);
                if (!$question) {
                    continue;
                }

                $isCorrect = $this->checkAnswer($question, $answerData['answer']);
                $score = $isCorrect ? $question->pivot->score : 0;

                ExamRecordAnswer::create([
                    'exam_record_id' => $record->id,
                    'question_id' => $answerData['question_id'],
                    'answer' => $answerData['answer'],
                    'is_correct' => $isCorrect,
                    'score' => $score,
                ]);

                $totalScore += $score;
            }

            // 先落库交卷时刻仍在客户端缓冲的监考事件（网络中断期间采集，恢复后随提交补发）
            if ($request->has('events') && is_array($request->events)) {
                app(\App\Services\ProctoringService::class)->storeEvents($record, $request->events);
            }

            $record->update([
                'end_time' => now(),
                'status' => 'graded',
            ]);

            // 自动确认违规事件、锁定原始分并计算扣分后最终得分
            app(\App\Services\ProctoringService::class)->applyOnSubmit($record, (float) $totalScore);
            $record->refresh();
        });

        return response()->json([
            'message' => '提交成功',
            'score' => $record->score,
            'base_score' => $record->base_score,
            'deduction' => $record->deduction,
            'anomaly_count' => $record->anomaly_count,
            'exam_record' => $record->load('answers'),
        ]);
    }

    public function myRecords(Request $request)
    {
        $records = ExamRecord::with('examPaper')
            ->where('user_id', $request->user()->id)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'records' => $records,
        ]);
    }

    public function showRecord(Request $request, ExamRecord $record)
    {
        if ($record->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权查看此记录'], 403);
        }

        $record->load(['examPaper.questions', 'answers.question']);

        return response()->json([
            'record' => $record,
        ]);
    }

    protected function checkAnswer(Question $question, string $userAnswer): bool
    {
        $correctAnswer = $question->answer;

        switch ($question->type) {
            case 'single_choice':
            case 'true_false':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            case 'multiple_choice':
                $userAnswers = explode(',', strtoupper(trim($userAnswer)));
                $correctAnswers = explode(',', strtoupper(trim($correctAnswer)));
                sort($userAnswers);
                sort($correctAnswers);
                return $userAnswers === $correctAnswers;
            case 'fill_blank':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            default:
                return false;
        }
    }
}

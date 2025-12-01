<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitAnswerRequest;
use App\Http\Requests\CompleteQuizRequest;
use App\Models\QuizAttempt;
use App\Models\UserAnswer;
use App\Models\Question;
use App\Models\Option;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class QuizAttemptController extends Controller
{
    /**
     * enviar resposta para uma questão do quiz
     */
    public function submitAnswer(SubmitAnswerRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $quizAttempt = QuizAttempt::find($request->quiz_attempt_id);
            $question = Question::find($request->question_id);
            $selectedOption = Option::find($request->option_id);

            // verificar se a opção pertence à questão
            if ($selectedOption->question_id !== $question->id) {
                return response()->json([
                    'message' => 'A opção selecionada não pertence a esta questão.'
                ], 422);
            }

            // verificar se usuário já respondeu esta questão
            $existingAnswer = UserAnswer::where('quiz_attempt_id', $quizAttempt->id)
                ->where('question_id', $question->id)
                ->first();

            if ($existingAnswer) {
                return response()->json([
                    'message' => 'Você já respondeu esta questão.'
                ], 409);
            }

            // verificar se a resposta está correta
            $isCorrect = $selectedOption->is_correct;
            $points = 10;

            // criar registro da resposta
            $userAnswer = UserAnswer::create([
                'quiz_attempt_id' => $quizAttempt->id,
                'question_id' => $question->id,
                'option_id' => $selectedOption->id,
                'is_correct' => $isCorrect
            ]);

            // atualizar estatísticas da tentativa
            if ($isCorrect) {
                $quizAttempt->increment('correct_answers');
                $quizAttempt->increment('score', $points);
            } else {
                $quizAttempt->increment('wrong_answers');
            }

            DB::commit();

            return response()->json([
                'message' => 'Resposta registrada com sucesso!',
                'is_correct' => $isCorrect,
                'correct_answer_id' => $isCorrect ? null : $question->correctOption->id,
                'current_score' => $quizAttempt->score,
                'correct_answers' => $quizAttempt->correct_answers,
                'wrong_answers' => $quizAttempt->wrong_answers
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Erro ao processar resposta.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * finalizar a tentativa de quiz
     */
    public function complete(CompleteQuizRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $quizAttempt = QuizAttempt::find($request->quiz_attempt_id);

            // verificar se todas as questões foram respondidas
            $answeredQuestions = UserAnswer::where('quiz_attempt_id', $quizAttempt->id)->count();
            $totalQuestions = 10;

            if ($answeredQuestions < $totalQuestions) {
                return response()->json([
                    'message' => 'Você precisa responder todas as questões antes de finalizar.',
                    'answered' => $answeredQuestions,
                    'total' => $totalQuestions
                ], 422);
            }

            // finalizar a tentativa
            $quizAttempt->update([
                'completed_at' => now(),
                'time_spent' => $request->time_spent ?? 0
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quiz finalizado com sucesso!',
                'attempt_id' => $quizAttempt->id,
                'final_score' => $quizAttempt->score,
                'score' => $quizAttempt->score,
                'correct_answers' => $quizAttempt->correct_answers,
                'wrong_answers' => $quizAttempt->wrong_answers,
                'time_spent' => $quizAttempt->time_spent,
                'total_questions' => $totalQuestions,
                'accuracy' => round(($quizAttempt->correct_answers / $totalQuestions) * 100, 2)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Erro ao finalizar quiz.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * buscar resultados de uma tentativa específica
     */
    public function results($attemptId): JsonResponse
    {
        try {
            // buscar tentativa do usuário atual
            $quizAttempt = QuizAttempt::where('id', $attemptId)
                ->where('user_id', Auth::id())
                ->first();

            if (!$quizAttempt) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tentativa não encontrada ou você não tem permissão para visualizá-la.',
                    'debug' => [
                        'attempt_id' => $attemptId,
                        'user_id' => Auth::id()
                    ]
                ], 404);
            }

            // buscar respostas do usuário
            $userAnswers = UserAnswer::where('quiz_attempt_id', $attemptId)
                ->with(['question', 'option'])
                ->get();

            $formattedResults = $userAnswers->map(function ($answer) {
                // buscar opção correta para esta questão
                $correctOption = Option::where('question_id', $answer->question_id)
                    ->where('is_correct', true)
                    ->first();

                return [
                    'question_text' => $answer->question->question_text ?? 'Pergunta não encontrada',
                    'user_answer' => $answer->option->option_text ?? 'Resposta não encontrada',
                    'correct_answer' => $correctOption->option_text ?? 'Resposta correta não encontrada',
                    'is_correct' => (bool) $answer->is_correct
                ];
            });

            return response()->json([
                'success' => true,
                'attempt_id' => (int) $quizAttempt->id,
                'final_score' => (int) $quizAttempt->score,
                'score' => (int) $quizAttempt->score,
                'correct_answers' => (int) $quizAttempt->correct_answers,
                'wrong_answers' => (int) $quizAttempt->wrong_answers,
                'total_questions' => $userAnswers->count() > 0 ? $userAnswers->count() : 10,
                'time_spent' => (int) $quizAttempt->time_spent,
                'completed_at' => $quizAttempt->completed_at,
                'results' => $formattedResults,
                'answers' => $formattedResults 
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar resultados.',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * buscar todas as tentativas do usuário
     */
    public function userAttempts(): JsonResponse
    {
        try {
            $attempts = QuizAttempt::with('quiz')
                ->where('user_id', Auth::id())
                ->whereNotNull('completed_at')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($attempt) {
                    return [
                        'id' => $attempt->id,
                        'score' => $attempt->score,
                        'correct_answers' => $attempt->correct_answers,
                        'wrong_answers' => $attempt->wrong_answers,
                        'time_spent' => $attempt->time_spent,
                        'completed_at' => $attempt->completed_at,
                        'created_at' => $attempt->created_at,
                        'quiz_title' => $attempt->quiz->title ?? 'Quiz'
                    ];
                });

            return response()->json([
                'success' => true,
                'attempts' => $attempts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar tentativas.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

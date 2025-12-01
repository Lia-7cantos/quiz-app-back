<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartQuizRequest;
use App\Models\Quiz;
use App\Models\Question;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class QuizController extends Controller
{
    /**
     * iniciar um novo quiz para o usuário autenticado
     */
    public function start(StartQuizRequest $request): JsonResponse
    {
        try {
            // buscar o único quiz disponível
            $quiz = Quiz::first();

            // verificar se usuário já tem tentativa ativa
            $activeAttempt = QuizAttempt::where('user_id', Auth::id())
                ->where('quiz_id', $quiz->id)
                ->whereNull('completed_at')
                ->first();

            if ($activeAttempt) {
                return response()->json([
                    'message' => 'Você já tem uma tentativa em andamento.',
                    'attempt_id' => $activeAttempt->id
                ], 409);
            }

            // nova tentativa
            $attempt = QuizAttempt::create([
                'user_id' => Auth::id(),
                'quiz_id' => $quiz->id,
                'score' => 0,
                'correct_answers' => 0,
                'wrong_answers' => 0,
                'time_spent' => 0
            ]);

            return response()->json([
                'message' => 'Quiz iniciado com sucesso!',
                'attempt_id' => $attempt->id,
                'quiz' => [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'total_questions' => $quiz->total_questions
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao iniciar quiz.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * buscar ranking de usuários
     */
    public function ranking(): JsonResponse
    {
        try {
            $ranking = QuizAttempt::with('user')
                ->select('user_id')
                ->selectRaw('MAX(score) as best_score')
                ->selectRaw('AVG(score) as avg_score')
                ->selectRaw('COUNT(*) as total_attempts')
                ->selectRaw('SUM(time_spent) as total_time')
                ->whereNotNull('completed_at')
                ->groupBy('user_id')
                ->orderBy('best_score', 'DESC')
                ->orderBy('avg_score', 'DESC')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'user_id' => $item->user_id,
                        'name' => $item->user->name ?? 'Jogador ' . $item->user_id,
                        'best_score' => $item->best_score,
                        'avg_score' => round($item->avg_score, 2),
                        'total_attempts' => $item->total_attempts,
                        'total_time' => $item->total_time
                    ];
                });

            return response()->json([
                'success' => true,
                'ranking' => $ranking
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao carregar ranking.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * buscar questões aleatórias para o quiz
     */
    public function questions(): JsonResponse
    {
        try {
            // buscar 10 questões aleatórias com suas opções
            $questions = Question::with(['options' => function ($query) {
                $query->select('id', 'question_id', 'option_text');
            }])
                ->inRandomOrder()
                ->limit(10)
                ->get()
                ->makeHidden(['created_at', 'updated_at']);

            // embaralhar opções de cada questão
            $questions->each(function ($question) {
                $question->options = $question->options->shuffle();
            });

            return response()->json([
                'success' => true,
                'questions' => $questions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao carregar questões.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

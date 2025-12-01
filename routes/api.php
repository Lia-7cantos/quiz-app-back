<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\QuizAttemptController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Rotas PÚBLICAS
Route::get('/ranking', [QuizController::class, 'ranking']);

// autenticação
Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required'
    ]);

    if (Auth::attempt($credentials)) {
        $user = Auth::user();
        $token = $user->createToken('quiz-token')->plainTextToken;
        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email
            ]
        ]);
    }

    return response()->json(['error' => 'Credenciais inválidas'], 401);
});

Route::post('/register', function (Request $request) {
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
        'password' => 'required|min:8|confirmed'
    ]);

    $user = \App\Models\User::create([
        'name' => $validated['name'],
        'email' => $validated['email'],
        'password' => bcrypt($validated['password'])
    ]);

    $token = $user->createToken('quiz-token')->plainTextToken;

    return response()->json([
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email
        ]
    ], 201);
});

Route::post('/quiz/reset-current', function (Request $request) {
    $user = $request->user();

    // deletar tentativas pendentes do usuário
    $deleted = \App\Models\QuizAttempt::where('user_id', $user->id)
        ->whereNull('completed_at')
        ->delete();

    return response()->json([
        'deleted' => $deleted,
        'message' => 'Tentativas pendentes removidas'
    ]);
})->middleware('auth:sanctum');

// buscar resultados especificos
Route::get('/fixed/results/{attemptId}', function ($attemptId) {
    try {
        $attempt = \App\Models\QuizAttempt::where('id', $attemptId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$attempt) {
            return response()->json([
                'message' => 'Tentativa não encontrada.',
                'debug' => ['attempt_id' => $attemptId, 'user_id' => Auth::id()]
            ], 404);
        }

        // buscar respostas
        $userAnswers = \App\Models\UserAnswer::where('quiz_attempt_id', $attemptId)->get();

        return response()->json([
            'success' => true,
            'attempt_id' => $attempt->id,
            'final_score' => $attempt->score,
            'correct_answers' => $attempt->correct_answers,
            'wrong_answers' => $attempt->wrong_answers,
            'total_questions' => $userAnswers->count(),
            'time_spent' => $attempt->time_spent,
            'completed_at' => $attempt->completed_at,
            'results' => $userAnswers->map(function ($answer) {
                $correctOption = \App\Models\Option::where('question_id', $answer->question_id)
                    ->where('is_correct', true)
                    ->first();

                return [
                    'question_text' => $answer->question->question_text ?? 'Pergunta',
                    'user_answer' => $answer->option->option_text ?? 'Resposta',
                    'correct_answer' => $correctOption->option_text ?? 'Correta',
                    'is_correct' => $answer->is_correct
                ];
            })
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Erro ao processar resultados.',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
})->middleware('auth:sanctum');

// Rotas PROTEGIDAS
Route::middleware(['auth:sanctum'])->group(function () {
    // Quiz Routes
    Route::post('/quiz/start', [QuizController::class, 'start']);
    Route::get('/quiz/questions', [QuizController::class, 'questions']);

    // Quiz Attempt Routes  
    Route::post('/quiz/answer', [QuizAttemptController::class, 'submitAnswer']);
    Route::post('/quiz/complete', [QuizAttemptController::class, 'complete']);
    Route::get('/quiz/attempts', [QuizAttemptController::class, 'userAttempts']);
    Route::get('/quiz/results/{attemptId}', [QuizAttemptController::class, 'results']);

    // Logout
    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout realizado']);
    });
});


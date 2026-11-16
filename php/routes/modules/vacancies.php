<?php

use App\Http\Controllers\Vacancies\PostingController;
use App\Http\Controllers\Vacancies\VacancyController;
use Illuminate\Support\Facades\Route;

// Отклики на вакансии с сайта: просмотр — vacancy.read, статусы и заметки — vacancy.manage
Route::get('/vacancies', [VacancyController::class, 'index'])->middleware('perm:vacancy.read')->name('vacancies');
Route::get('/vacancies/postings', [PostingController::class, 'index'])->middleware('perm:vacancy.read')->name('vacancies.postings');
Route::post('/vacancies/postings', [PostingController::class, 'store'])->middleware('perm:vacancy.manage')->name('vacancies.postings.store');
Route::put('/vacancies/postings/{id}', [PostingController::class, 'update'])->whereUuid('id')->middleware('perm:vacancy.manage')->name('vacancies.postings.update');
Route::patch('/vacancies/postings/{id}/status', [PostingController::class, 'status'])->whereUuid('id')->middleware('perm:vacancy.manage')->name('vacancies.postings.status');
Route::delete('/vacancies/postings/{id}', [PostingController::class, 'destroy'])->whereUuid('id')->middleware('perm:vacancy.manage')->name('vacancies.postings.destroy');
Route::get('/vacancies/{id}', [VacancyController::class, 'show'])->whereUuid('id')->middleware('perm:vacancy.read')->name('vacancies.show');
Route::post('/vacancies/rescore', [VacancyController::class, 'rescore'])->middleware('perm:vacancy.manage')->name('vacancies.rescore');
Route::patch('/vacancies/{id}', [VacancyController::class, 'update'])->whereUuid('id')->middleware('perm:vacancy.manage')->name('vacancies.update');
Route::delete('/vacancies/{id}', [VacancyController::class, 'destroy'])->whereUuid('id')->middleware('perm:vacancy.manage')->name('vacancies.destroy');

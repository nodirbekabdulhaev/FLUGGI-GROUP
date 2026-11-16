<?php

use App\Http\Controllers\Vacancies\VacancyController;
use Illuminate\Support\Facades\Route;

// Отклики на вакансии с сайта: просмотр — vacancy.read, статусы и заметки — vacancy.manage
Route::get('/vacancies', [VacancyController::class, 'index'])->middleware('perm:vacancy.read')->name('vacancies');
Route::get('/vacancies/{id}', [VacancyController::class, 'show'])->whereUuid('id')->middleware('perm:vacancy.read')->name('vacancies.show');
Route::post('/vacancies/rescore', [VacancyController::class, 'rescore'])->middleware('perm:vacancy.manage')->name('vacancies.rescore');
Route::patch('/vacancies/{id}', [VacancyController::class, 'update'])->whereUuid('id')->middleware('perm:vacancy.manage')->name('vacancies.update');
Route::delete('/vacancies/{id}', [VacancyController::class, 'destroy'])->whereUuid('id')->middleware('perm:vacancy.manage')->name('vacancies.destroy');

<?php

use App\Http\Controllers\Site\PortalApiController as Portal;
use App\Http\Middleware\{SiteGateway,PortalToken};
use Illuminate\Support\Facades\Route;

Route::prefix('site/v1')->middleware(SiteGateway::class)->group(function() {
    Route::get('catalog',[Portal::class,'catalog']);
    Route::get('postal/{zip}',\App\Http\Controllers\PostalAddressController::class);
    Route::post('account-check',[Portal::class,'accountCheck']);
    Route::post('login',[Portal::class,'login']);
    Route::post('register',[Portal::class,'register']);
    Route::get('verify-email/{user}/{hash}',[Portal::class,'verifyEmail'])->middleware('signed:relative')->name('site-api.verify-email');
    Route::post('verification-resend',[Portal::class,'resendVerificationByEmail']);
    Route::post('forgot-password',[Portal::class,'forgotPassword']);
    Route::post('reset-password',[Portal::class,'resetPassword']);
    Route::middleware(PortalToken::class)->group(function() {
        Route::get('me',[Portal::class,'me']);
        Route::post('logout',[Portal::class,'logout']);
        Route::post('verification',[Portal::class,'resendVerification']);
        Route::post('pets',[Portal::class,'addPet']);
        Route::post('proposals',[Portal::class,'createProposal']);
        Route::get('proposals/{proposal}',[Portal::class,'proposal']);
        Route::get('proposals/{proposal}/pdf',[Portal::class,'reviewPdf']);
        Route::get('proposals/{proposal}/pages/{page}',[Portal::class,'reviewPage'])->whereNumber('page');
        Route::post('proposals/{proposal}/accept',[Portal::class,'accept']);
        Route::post('proposals/{proposal}/document',[Portal::class,'generateDocument']);
        Route::get('documents/{document}',[Portal::class,'document']);
        Route::get('documents/{document}/pages/{page}',[Portal::class,'documentPage'])->whereNumber('page');
        Route::post('subscriptions/{subscription}/checkout',[Portal::class,'checkout']);
        Route::post('subscriptions/{subscription}/cancellation',[Portal::class,'requestCancellation']);
        Route::post('invoices/{invoice}/payment',[Portal::class,'invoicePayment']);
    });
});

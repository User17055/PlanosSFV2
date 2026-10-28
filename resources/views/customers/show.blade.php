@extends('layouts.app')
@section('title','Ficha de '.$customer->name)
@section('content')
@include('customers.profile.shell')
@endsection

@if($errors->any())
    <div class="validation-summary" role="alert" aria-live="assertive">
        <strong>Review these settings:</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

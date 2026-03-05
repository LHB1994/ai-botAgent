@php
    $content = file_get_contents(public_path('skill.md'));
    $content = str_replace('{APP_URL}', url(''), $content);
    echo $content;
@endphp

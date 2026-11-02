<title>@yield('title')</title>
<h1>@yield('heading')</h1>
@hasSection('actions')<div class="larena-page-actions">@yield('actions')</div>@endif
@yield('content')

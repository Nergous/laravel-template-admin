{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($entries as $entry)
  <url>
@foreach (array_filter($entry, fn ($value) => $value !== null) as $tag => $value)
    <{{ $tag }}>{{ $value }}</{{ $tag }}>
@endforeach
  </url>
@endforeach
</urlset>

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WeatherController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'city' => 'required|string',
            'units' => 'sometimes|in:metric,imperial'
        ]);

        try {
            $city = $request->query('city');
            $units = $request->query('units', 'metric');
            
            $location = $this->getLocation($city);
            if (!$location) {
                return response()->json(['error' => 'Location not found'], 404);
            }

            $currentWeather = $this->getCurrentWeather($location, $units);
            $forecast = $this->getForecast($location, $units);

            return response()->json([
                'city' => $currentWeather['name'],
                'country' => $location['country'] ?? null,
                'date' => now()->format('d F Y'),
                'current' => $this->formatCurrentWeather($currentWeather),
                'forecast' => $this->formatForecast($forecast),
                'units' => $units
            ]);

        } catch (\Exception $e) {
            Log::error('Weather API error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch weather data'], 500);
        }
    }

    private function getLocation(string $city): ?array
    {
        $response = Http::get(config('services.openweather.geo_url'), [
            'q' => $city,
            'limit' => 1,
            'appid' => config('services.openweather.api_key'),
        ]);

        return $response->json()[0] ?? null;
    }

    private function getCurrentWeather(array $location, string $units): array
{
    $cacheKey = "weather_{$location['lat']}_{$location['lon']}_{$units}";
    
    return Cache::remember($cacheKey, now()->addHour(), function() use ($location, $units) {
        $response = Http::get(config('services.openweather.weather_url'), [
            'lat' => $location['lat'],
            'lon' => $location['lon'],
            'units' => $units,
            'appid' => config('services.openweather.api_key'),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to fetch current weather');
        }

        return $response->json();
    });
}

    private function getForecast(array $location, string $units): array
    {
        $response = Http::get(config('services.openweather.forecast_url'), [
            'lat' => $location['lat'],
            'lon' => $location['lon'],
            'units' => $units,
            'appid' => config('services.openweather.api_key'),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to fetch forecast');
        }

        return $response->json();
    }

    private function formatCurrentWeather(array $data): array
    {
        return [
            'temperature' => round($data['main']['temp']),
            'feels_like' => round($data['main']['feels_like']),
            'description' => $data['weather'][0]['description'],
            'icon' => $data['weather'][0]['icon'],
            'wind_speed' => $data['wind']['speed'],
            'wind_deg' => $data['wind']['deg'] ?? null,
            'humidity' => $data['main']['humidity'],
            'pressure' => $data['main']['pressure'],
            'sunrise' => isset($data['sys']['sunrise']) ? date('H:i', $data['sys']['sunrise']) : null,
            'sunset' => isset($data['sys']['sunset']) ? date('H:i', $data['sys']['sunset']) : null,
        ];
    }

    private function formatForecast(array $data): array
    {
        $dailyForecasts = [];
        $groupedByDay = [];
        
        // Group forecasts by day
        foreach ($data['list'] as $forecast) {
            $date = date('Y-m-d', $forecast['dt']);
            $groupedByDay[$date][] = $forecast;
        }
        
        // Get one forecast per day (midday if possible)
        foreach (array_slice($groupedByDay, 0, 3) as $day => $forecasts) {
            $middayForecast = collect($forecasts)
                ->sortBy(fn($f) => abs(12 - date('H', $f['dt'])))
                ->first();
                
            $dailyForecasts[] = [
                'date' => date('D, M j', strtotime($day)),
                'temp_max' => round($middayForecast['main']['temp_max']),
                'temp_min' => round($middayForecast['main']['temp_min']),
                'description' => $middayForecast['weather'][0]['description'],
                'icon' => $middayForecast['weather'][0]['icon'],
                'wind' => $middayForecast['wind']['speed'],
                'humidity' => $middayForecast['main']['humidity'],
            ];
        }
        
        return $dailyForecasts;
    }
}
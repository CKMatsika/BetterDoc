<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $branding['name'] ?? 'BetterDoc DMS' }}</title>
</head>
<body style="margin:0;padding:24px;background-color:#F7F8FA;color:#0F172A;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:620px;margin:0 auto;background-color:#FFFFFF;border-radius:14px;border:1px solid #E2E8F0;overflow:hidden;">
        <tr>
            <td style="padding:20px 28px;background-color:{{ $branding['primary_color'] ?? '#0E7C66' }};color:{{ $branding['primary_fg'] ?? '#FFFFFF' }};">
                <div style="font-size:18px;font-weight:700;line-height:1.3;">{{ $branding['name'] ?? 'BetterDoc DMS' }}</div>
                @if(!empty($branding['tagline']))
                    <div style="font-size:12px;opacity:0.9;margin-top:4px;">{{ $branding['tagline'] }}</div>
                @endif
            </td>
        </tr>
        <tr>
            <td style="padding:28px;">
                <h1 style="margin:0 0 12px 0;font-size:18px;font-weight:700;color:#0F172A;">{{ $headingText }}</h1>
                <p style="margin:0 0 20px 0;font-size:14px;line-height:1.6;color:#334155;">{{ $bodyText }}</p>
                @if(!empty($actionLabel) && !empty($actionUrl))
                    <p style="margin:0 0 20px 0;">
                        <a href="{{ $actionUrl }}" style="display:inline-block;padding:11px 20px;border-radius:10px;background-color:{{ $branding['primary_color'] ?? '#0E7C66' }};color:{{ $branding['primary_fg'] ?? '#FFFFFF' }};text-decoration:none;font-size:14px;font-weight:600;">
                            {{ $actionLabel }}
                        </a>
                    </p>
                @endif
                <div style="margin-top:24px;padding-top:16px;border-top:1px solid #E2E8F0;font-size:12px;color:#475569;line-height:1.5;">
                    @if(!empty($branding['support_email']) || !empty($branding['support_phone']))
                        <div>
                            Help Desk:
                            @if(!empty($branding['support_email']))
                                <span>{{ $branding['support_email'] }}</span>
                            @endif
                            @if(!empty($branding['support_phone']))
                                <span> &middot; {{ $branding['support_phone'] }}</span>
                            @endif
                        </div>
                    @endif
                    @if(!empty($branding['address']))
                        <div>{{ $branding['address'] }}</div>
                    @endif
                    @if(!empty($branding['show_powered_by']))
                        <div style="margin-top:8px;color:#64748B;">Powered by BetterDoc DMS</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>
</body>
</html>

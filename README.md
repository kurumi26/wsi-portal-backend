<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## WSI Helpdesk API

The portal now uses dedicated `helpdesk_tickets` and `helpdesk_ticket_activities` tables as the source of truth for support issues.

Supported ticket statuses:

- `Open`
- `In Progress`
- `Escalated`
- `Resolved`
- `Closed`

Supported ticket priorities:

- `Low`
- `Normal`
- `High`
- `Urgent`

### Customer issue submission

`POST /api/customer-services/{customerService}/report-issue`

Example request:

```json
{
	"title": "Website not loading",
	"message": "Customer reports the website is down.",
	"category": "Technical",
	"priority": "High"
}
```

Example response:

```json
{
	"message": "Issue reported. Support will review this shortly.",
	"ticket": {
		"id": 123,
		"reference": "T-000123",
		"title": "Website not loading",
		"message": "Customer reports the website is down.",
		"category": "Technical",
		"status": "Open",
		"priority": "High",
		"source": "customer_portal",
		"createdAt": "2026-04-15T10:00:00Z",
		"updatedAt": "2026-04-15T10:00:00Z",
		"resolvedAt": null,
		"closedAt": null,
		"serviceId": 88,
		"serviceName": "Business Hosting",
		"clientId": 45,
		"clientName": "Acme Corp",
		"clientEmail": "admin@acme.com",
		"assignedTo": null,
		"activities": [
			{
				"id": 1,
				"action": "ticket_created",
				"oldValue": null,
				"newValue": {
					"reference": "T-000123",
					"status": "Open",
					"priority": "High"
				},
				"note": null,
				"createdAt": "2026-04-15T10:00:00Z",
				"actor": {
					"id": 45,
					"name": "Acme Corp",
					"role": "Customer"
				}
			}
		]
	}
}
```

### Admin helpdesk list

`GET /api/admin/helpdesk/tickets`

Supported query parameters:

- `status`
- `assigned_to_user_id`
- `category`
- `customer_id`
- `service_id`
- `date_from`
- `date_to`
- `search`

Example request:

`GET /api/admin/helpdesk/tickets?status=Open&assigned_to_user_id=9&search=hosting`

### Admin helpdesk update

`PATCH /api/admin/helpdesk/tickets/{ticket}`

Example request:

```json
{
	"assigned_to_user_id": 9,
	"status": "In Progress",
	"priority": "High",
	"internal_note": "Assigned to infrastructure support for deeper review."
}
```

Example response:

```json
{
	"message": "Helpdesk ticket updated successfully.",
	"ticket": {
		"id": 123,
		"reference": "T-000123",
		"title": "Website not loading",
		"message": "Customer reports the website is down.",
		"category": "Technical",
		"status": "In Progress",
		"priority": "High",
		"source": "customer_portal",
		"createdAt": "2026-04-15T10:00:00Z",
		"updatedAt": "2026-04-15T11:30:00Z",
		"resolvedAt": null,
		"closedAt": null,
		"serviceId": 88,
		"serviceName": "Business Hosting",
		"clientId": 45,
		"clientName": "Acme Corp",
		"clientEmail": "admin@acme.com",
		"assignedTo": {
			"id": 9,
			"name": "John Support",
			"role": "Technical Support"
		}
	}
}
```

### Admin helpdesk detail

`GET /api/admin/helpdesk/tickets/{ticket}`

Returns the full ticket record together with customer, service, assigned agent, timestamps, and activity history.

### Customer ticket tracker

`GET /api/helpdesk/tickets/me`

Returns only the authenticated customer's tickets in summary form:

```json
[
	{
		"id": 123,
		"reference": "T-000123",
		"title": "Website not loading",
		"serviceName": "Business Hosting",
		"category": "Technical",
		"status": "In Progress",
		"createdAt": "2026-04-15T10:00:00Z",
		"updatedAt": "2026-04-15T11:30:00Z"
	}
]
```

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

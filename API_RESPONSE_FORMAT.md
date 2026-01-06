# API Response Format Documentation

All API endpoints in this application return responses in a consistent, standardized format. This ensures predictable behavior for frontend developers and better error handling.

## Standard Response Structure

### Success Response

```json
{
  "success": true,
  "message": "Operation successful",
  "data": {
    // Response data here
  },
  "meta": {
    // Optional metadata (pagination, timestamps, etc.)
  }
}
```

### Error Response

```json
{
  "success": false,
  "message": "Error message",
  "errors": {
    // Validation errors or detailed error information
  },
  "meta": {
    // Optional metadata
  }
}
```

### Paginated Response

```json
{
  "success": true,
  "message": "Data retrieved successfully",
  "data": [
    // Array of items
  ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 100,
    "last_page": 7,
    "from": 1,
    "to": 15
  }
}
```

## HTTP Status Codes

The API uses standard HTTP status codes:

- `200 OK` - Successful GET, PUT, PATCH requests
- `201 Created` - Successful POST requests (resource created)
- `204 No Content` - Successful DELETE requests
- `400 Bad Request` - Client error (invalid request)
- `401 Unauthorized` - Authentication required
- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - Resource not found
- `422 Unprocessable Entity` - Validation errors
- `500 Internal Server Error` - Server error

## Usage in Controllers

### Using the Trait (Recommended)

All controllers extend `App\Http\Controllers\Controller` which includes `ApiResponseTrait`:

```php
use App\Http\Controllers\Controller;

class ExampleController extends Controller
{
    public function index()
    {
        $data = Model::all();
        return $this->successResponse($data, 'Data retrieved successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([...]);
        $model = Model::create($validated);
        return $this->createdResponse($model, 'Resource created successfully');
    }

    public function show($id)
    {
        $model = Model::findOrFail($id);
        return $this->successResponse($model, 'Resource retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $model = Model::findOrFail($id);
        $validated = $request->validate([...]);
        $model->update($validated);
        return $this->successResponse($model, 'Resource updated successfully');
    }

    public function destroy($id)
    {
        $model = Model::findOrFail($id);
        $model->delete();
        return $this->noContentResponse('Resource deleted successfully');
    }
}
```

### Using the Class Directly

```php
use App\Http\Responses\ApiResponse;

return ApiResponse::success($data, 'Success message');
return ApiResponse::error('Error message', 400);
return ApiResponse::validationError($errors);
return ApiResponse::unauthorized('Unauthorized');
return ApiResponse::forbidden('Forbidden');
return ApiResponse::notFound('Resource not found');
return ApiResponse::paginated($paginatedData);
```

## Available Response Methods

### Success Responses

- `success($data, $message, $statusCode, $meta)` - Standard success response
- `created($data, $message, $meta)` - Resource created (201)
- `noContent($message)` - No content response (204)
- `paginated($data, $message, $meta)` - Paginated response

### Error Responses

- `error($message, $statusCode, $errors, $meta)` - Standard error response
- `validationError($errors, $message)` - Validation errors (422)
- `unauthorized($message)` - Unauthorized (401)
- `forbidden($message)` - Forbidden (403)
- `notFound($message)` - Not found (404)

## Examples

### Authentication Example

```php
public function login(Request $request)
{
    // ... validation and authentication logic ...
    
    $token = $user->createToken('auth-token')->plainTextToken;
    
    return $this->successResponse([
        'user' => $user,
        'token' => $token,
    ], 'Login successful');
}
```

### Validation Error Example

```php
try {
    $validated = $request->validate([
        'email' => 'required|email',
        'password' => 'required|min:8',
    ]);
} catch (ValidationException $e) {
    return $this->validationErrorResponse($e->errors());
}
```

### Paginated Response Example

```php
public function index(Request $request)
{
    $perPage = $request->get('per_page', 15);
    $projects = Project::paginate($perPage);
    
    return $this->paginatedResponse($projects, 'Projects retrieved successfully');
}
```

## Frontend Integration

All frontend API calls should handle the standard response format:

```javascript
// Success response handling
if (response.data.success) {
    const data = response.data.data;
    const message = response.data.message;
    // Handle success
}

// Error response handling
if (!response.data.success) {
    const message = response.data.message;
    const errors = response.data.errors || {};
    // Handle error
}
```

## Benefits

1. **Consistency** - All endpoints follow the same format
2. **Predictability** - Frontend developers know what to expect
3. **Error Handling** - Standardized error format makes error handling easier
4. **Maintainability** - Changes to response format only need to be made in one place
5. **Debugging** - Easier to debug issues with consistent response structure


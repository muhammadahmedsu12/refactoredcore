As per the code i have improve a version of code by adding globalhelper and globalconstant in file
also add refactor or code as per needed however i found still some more improvement will be needed to make this
code more improved.

1. Lack of Error Handling

Methods like Language::findOrFail($id) and UserMeta::where() can throw exceptions need to handle this.

2. should use whereIn() to reduce the number of database hits because it accepts array

3. Add comments or define the conditions in constants to make the logic clearer for expiry.

4. validate the object of $user use isset or other function it will break the code when it return null.

5. Carbon methods like addMinutes or addHours modify objects in place, causing unintended side effects.

6. Redundant variable $language1. it should be use global.

-----------------

1. Use validation methods or a Request class

$validation = $request->validate([
    'name' => 'required|string|max:255',
    'email' => 'required|email|unique:users,email',
    'role' => 'required',
]);

2. provide defualts values before storing in db 
$model->name = $request['name'] ?? 'NA';

3. use batch quries.
GetUserTowns::insert(array_map(fn($townId) => [
    'user_id' => $getModel->id,
    'town_id' => $getTownId,
], $request['user_towns_projects']));

4. use try catch block and use log when error is catch
try {
    // your execution code
} catch (\Exception $e) {
    Log::error('Logger initialization failed', [
        'error_message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}



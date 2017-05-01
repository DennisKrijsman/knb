<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptAnswerRequest;
use App\Http\Requests\AddVoteRequest;
use App\Http\Requests\CreatePostRequest;
use App\Http\Requests\UpdateAnswerRequest;
use App\Http\Requests\UpdatePostRequest;
use Session;
use App\Post;
use App\Vote;
use App\Comment;
use App\Tag;
use Illuminate\Http\Request;


class PostController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return mixed
     */
    public function index()
    {
        return view('posts.index')->with([
            'posts' => Post::with('author')->orderBy('created_at', 'DESC')->where('post_id', NULL)->paginate(10)
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return mixed
     */
    public function create()
    {
        $tags = Tag::all();
        return view('posts/create')->with(compact('tags'));
    }

    public function answer($id)
    {
        $post = Post::with('author', 'author.houseRole', 'author.houseRole.house')->findOrFail($id);

        return view('posts.create-answer', [
            'post' => $post,
        ]);
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param CreatePostRequest $request
     * @return mixed
     */
    public function store(CreatePostRequest $request)
    {
        try
        {
            \DB::beginTransaction();
            $post = Post::create([
                'title' => $request->get('title'),
                'content' => $request->get('content'),
                'post_id' => $request->has('question_id') ? $request->get('question_id') : null,
                'author_id' => \Auth::id(),
            ]);

            if ($request->has('tag'))
            {
                $post->tags()->attach($request->get('tag'));
            }
            \DB::commit();
        } catch (\Exception $e)
        {
            \DB::rollback();
            return redirect()->back()->with('error', 'error creating post.');
        }


        $redirect = $request->has('question_id') ? $request->get('question_id') : $post->id;

        return redirect()->action('PostController@show', $redirect)->with('Success','Post succesfully created.');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return mixed
     */
    public function show($id)
    {
        //handle views per post
        //TODO: there's gotta be a better place to put this...
        if (! Session::has('viewed')) {
            Session::put('viewed', []);
        }

        if (! in_array($id, Session::get('viewed'))) {
            Session::push('viewed', $id);
            Post::find($id)->increment('views');
        }

        $post = Post::with([
            'comments', 'comments.author', 'author', 'author.houseRole', 'author.houseRole.house', 'votes'
        ]);



        return view('posts.show', [
            'post' => $post->findOrFail($id),
            'replies' => Post::with('votes')->where('post_id', $id)
                ->orderBy('accepted_answer', 'DESC')
                ->orderBy('votes', 'DESC')
                ->orderBy('created_at', 'DESC')
                ->get(),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return mixed
     */
    public function edit($id)
    {

        return view('posts/edit', [
            'post' => Post::with('author', 'author.houseRole', 'author.houseRole.house', 'votes')->findOrFail($id),
            'tags' => Tag::all()
        ]);
    }

    /**
     *
     * Show form for editing answer
     *
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     */
    public function editAnswer($id)
    {
        $post =  Post::with('author', 'author.houseRole', 'author.houseRole.house', 'votes')->findOrFail($id);
        $tags =  Tag::all();
        return view('posts/edit-answer', compact('post', 'tags'));

    }


    /**
     * Update the specified resource in storage.
     *
     * @param UpdatePostRequest $request
     * @param  int $id
     * @return mixed
     */
    public function update(UpdatePostRequest $request, $id)
    {
        $post = Post::findOrFail($id);

        try
        {
            \DB::beginTransaction();
            $post->update($request->all());
            if($request->has('tag'))
            {
                $post->tags()->detach();
                $post->tags()->attach($request->get('tag'));
            }
            \DB::commit();
        } catch(\Exception $e)
        {
            \DB::rollback();
            return redirect()->back()->with('error', 'Error editing post.: <br>' . $e->getMessage());
        }


        return redirect()->action('PostController@show', $id)->with('success', 'Succesfully edited your question.');

    }

    public function updateAnswer(UpdateAnswerRequest $request, $id)
    {
        $post = Post::findOrFail($id);
        $post->update($request->all());
        return redirect()->action('PostController@show', $post->parent->id)->with('success', 'Succesfully edited your answer.');
    }

    /**
     * Accepts this as answer to question
     * @param AcceptAnswerRequest $request
     * @param $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function accept(AcceptAnswerRequest $request, $id)
    {
        Post::find($id)->update([
            'accepted_answer' => $request->get('accepted'),
        ]);

        return redirect()->back()->with('success', 'Answer has been accepted.');
    }


    public function vote(AddVoteRequest $request, $id)
    {
        $data = [
            'user_id' => \Auth::user()->id,
            'post_id' => $id
        ];
        if ($request->get('vote') == 'up')
        {
             $data['vote'] = 1;
            Post::find($id)->increment('votes');
        } else
        {
            $data['vote'] = -1;
            Post::find($id)->decrement('votes');
        }

        Vote::create($data);

        return redirect()->back();
    }

    public function flag(Request $request, $id)
    {
        $post = Post::findOrFail($id);
        $post->increment('flags');


        return back();

    }


    public function filter(Request $request)
    {
        if (empty($request->tags))
        {
            return redirect()->action('HomeController@index');
        }
        $posts = Post::with('author')->
                whereHas('tags', function($query) use ($request){
                    $query->whereIn('tags.id', $request->tags);
                })->
                orderBy('created_at', 'DESC')->
                where('post_id', NULL)->
                paginate(10);

        return view('home', [
            'posts' => $posts,
            'searchTags' => $request->tags
        ]);
    }

    public function search(Request $request)
    {
        if (empty($request->get('query')))
        {
            return redirect()->action('HomeController@index');
        }
        $query = $request->get('query');
        $posts = Post::with('author')->
        orderBy('created_at', 'DESC')->
        where('post_id', NULL)->
        where('content', 'like', "%$query%")->
        orWhere('title', 'like', "%$query%")->
        paginate(10);


        return view('home', [
            'posts' => $posts,
            'query' => $request->get('query')
        ]);

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return mixed
     */
    public function destroy($id)
    {
        Post::findOrFail($id)->delete();

    }
}
